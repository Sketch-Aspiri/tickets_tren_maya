<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\Cloud\CloudSyncException;
use App\Services\CloudSheetSyncService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reemplaza el Excel en la nube con todos los tickets y actividades. Programado cada hora (routes/console.php)
 * solo si CLOUD_SYNC_ENABLED=true. Si falla, el archivo anterior queda intacto.
 */
class SyncCloudSheetCommand extends Command
{
    protected $signature = 'exports:sync-cloud';

    protected $description = 'Actualiza el Excel de tickets y actividades en OneDrive/SharePoint';

    public function handle(CloudSheetSyncService $sync, AuditLogger $audit): int
    {
        if (! config('tickets.cloud_sync.enabled')) {
            $this->components->warn('La sincronizacion a la nube esta desactivada (CLOUD_SYNC_ENABLED=false).');

            return self::SUCCESS;
        }

        try {
            $result = $sync->sync();
        } catch (Throwable $exception) {
            // Solo el mensaje propio (CloudSyncException nunca lleva tokens ni secretos); otros errores se reportan.
            $message = $exception instanceof CloudSyncException ? $exception->getMessage() : 'Error inesperado al generar o subir el archivo.';

            if (! $exception instanceof CloudSyncException) {
                report($exception);
            }

            $audit->record('exports', 'cloud_sync_failed', null, null, [], [], ['reason' => $message]);
            $this->components->error($message);

            return self::FAILURE;
        }

        $this->components->info("Excel actualizado: {$result['tickets']} tickets y {$result['activities']} actividades.".($result['truncated'] ? ' (recortado)' : ''));

        return self::SUCCESS;
    }
}
