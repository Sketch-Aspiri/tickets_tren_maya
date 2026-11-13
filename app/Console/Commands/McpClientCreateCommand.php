<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\McpClient;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Registra un cliente del servidor MCP (decision 94). Actua COMO la cuenta indicada, asi que sus permisos y su
 * alcance son los de esa cuenta. El token se muestra UNA sola vez; en la BD solo queda su hash.
 */
class McpClientCreateCommand extends Command
{
    protected $signature = 'mcp:cliente-crear {nombre : Nombre del cliente (p. ej. grokbot)} {correo : Correo de la cuenta con la que actuara} {--forzar : Permite una cuenta de administrador (alcance global)} {--escritura : Permite herramientas de escritura (por defecto solo lectura)} {--dashboard=none : Acceso a las herramientas del Dashboard: none, read o write} {--ip=* : IPs permitidas (opcional, repetible)}';

    protected $description = 'Crea un cliente MCP y muestra su token una sola vez';

    public function handle(AuditLogger $audit): int
    {
        $name = trim((string) $this->argument('nombre'));
        $user = User::query()->where('email', (string) $this->argument('correo'))->first();

        // El mismo conjunto de caracteres que acepta el Dashboard para el nombre del cliente en su bitacora.
        if (preg_match('/^[A-Za-z0-9 ._-]{1,80}$/', $name) !== 1) {
            $this->error('El nombre debe tener de 1 a 80 caracteres: letras, numeros, espacio, punto, guion y guion bajo.');

            return self::FAILURE;
        }

        if (McpClient::query()->where('name', $name)->exists()) {
            $this->error('Ya existe un cliente con ese nombre.');

            return self::FAILURE;
        }

        if ($user === null || ! $user->canAccessApplication() || $user->isEcosystemSystem()) {
            $this->error('La cuenta no existe, no esta activa o no tiene rol.');

            return self::FAILURE;
        }

        // El token no pasa por el 2FA: una cuenta con alcance global lo heredaria completo. Se pide una cuenta de
        // minimo alcance (p. ej. coordinador de la coordinacion), o --forzar de forma explicita.
        if ($user->seesAllTeams() && ! $this->option('forzar')) {
            $this->error('Esa cuenta tiene alcance global y el token omite el 2FA. Usa una cuenta de menor alcance o repite con --forzar.');

            return self::FAILURE;
        }

        $dashboard = (string) $this->option('dashboard');

        if (! in_array($dashboard, [McpClient::DASHBOARD_NONE, McpClient::DASHBOARD_READ, McpClient::DASHBOARD_WRITE], true)) {
            $this->error('--dashboard debe ser none, read o write.');

            return self::FAILURE;
        }

        if ($dashboard === McpClient::DASHBOARD_WRITE && ! $this->option('escritura')) {
            $this->error('El acceso de escritura al Dashboard exige que el cliente tenga --escritura.');

            return self::FAILURE;
        }

        $ips = array_values(array_filter((array) $this->option('ip')));

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $this->error('IP no valida: '.$ip);

                return self::FAILURE;
            }
        }

        [$plain, $hash] = McpClient::newToken();

        $client = new McpClient(['name' => $name, 'user_id' => $user->getKey(), 'can_write' => (bool) $this->option('escritura'), 'dashboard_access' => $dashboard, 'allowed_ips' => $ips === [] ? null : $ips]);
        $client->forceFill(['token_hash' => $hash])->save();

        $audit->record('mcp', 'client_created', null, null, [], [
            'client' => $name,
            'user_id' => $user->getKey(),
            'can_write' => $client->can_write,
            'dashboard' => $dashboard,
            'ips' => count($ips),
        ]);

        $this->info(sprintf('Cliente «%s» creado (actua como la cuenta #%d, %s).', $name, $user->getKey(), $client->can_write ? 'lectura y escritura' : 'solo lectura'));
        $this->warn('Token (se muestra una sola vez, guardalo ahora):');
        $this->line($plain);

        return self::SUCCESS;
    }
}
