<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\McpClient;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Revoca un cliente del servidor MCP (decision 94): su token deja de funcionar de inmediato. No se borra la fila
 * para conservar el rastro de quien fue y cuando se uso por ultima vez.
 */
class McpClientRevokeCommand extends Command
{
    protected $signature = 'mcp:cliente-revocar {nombre : Nombre del cliente}';

    protected $description = 'Revoca el token de un cliente MCP';

    public function handle(AuditLogger $audit): int
    {
        $client = McpClient::query()->where('name', (string) $this->argument('nombre'))->first();

        if ($client === null) {
            $this->error('No existe ese cliente.');

            return self::FAILURE;
        }

        if ($client->isRevoked()) {
            $this->warn('Ese cliente ya estaba revocado.');

            return self::SUCCESS;
        }

        $client->forceFill(['revoked_at' => now()])->save();
        $audit->record('mcp', 'client_revoked', null, null, [], ['client' => $client->name, 'user_id' => $client->user_id]);

        $this->info('Cliente «'.$client->name.'» revocado.');

        return self::SUCCESS;
    }
}
