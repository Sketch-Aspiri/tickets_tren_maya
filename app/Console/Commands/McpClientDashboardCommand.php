<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\McpClient;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Cambia el acceso de un cliente MCP existente a las herramientas del Dashboard (decision 97): `none`, `read`
 * (consultas) o `write` (consultas y captura de asistencia; exige que el cliente ya tenga escritura).
 */
class McpClientDashboardCommand extends Command
{
    protected $signature = 'mcp:cliente-dashboard {nombre : Nombre del cliente} {nivel : none, read o write}';

    protected $description = 'Define el acceso de un cliente MCP a las herramientas del Dashboard';

    public function handle(AuditLogger $audit): int
    {
        $client = McpClient::query()->where('name', (string) $this->argument('nombre'))->first();
        $level = (string) $this->argument('nivel');

        if ($client === null || $client->isRevoked()) {
            $this->error('No existe ese cliente o ya fue revocado.');

            return self::FAILURE;
        }

        if (! in_array($level, [McpClient::DASHBOARD_NONE, McpClient::DASHBOARD_READ, McpClient::DASHBOARD_WRITE], true)) {
            $this->error('El nivel debe ser none, read o write.');

            return self::FAILURE;
        }

        if ($level === McpClient::DASHBOARD_WRITE && ! $client->can_write) {
            $this->error('Ese cliente es de solo lectura: el acceso de escritura al Dashboard exige un cliente con escritura.');

            return self::FAILURE;
        }

        $before = $client->dashboard_access;
        $client->forceFill(['dashboard_access' => $level])->save();

        $audit->record('mcp', 'client_dashboard_changed', null, null, ['dashboard' => $before], ['dashboard' => $level], ['client' => $client->name]);

        $this->info(sprintf('Cliente «%s»: acceso al Dashboard %s → %s.', $client->name, $before, $level));

        return self::SUCCESS;
    }
}
