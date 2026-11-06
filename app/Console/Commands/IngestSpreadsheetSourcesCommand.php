<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SpreadsheetSource;
use App\Services\SheetInbox\SheetInboxException;
use App\Services\SheetInbox\SheetInboxImporter;
use Illuminate\Console\Command;

/**
 * Lee cada hoja de Excel conectada y activa y deja sus filas nuevas en la bandeja de su coordinación. Un fallo en
 * una hoja (queda en `last_error`) no detiene a las demás; el comando termina con código 1 si alguna falló.
 */
class IngestSpreadsheetSourcesCommand extends Command
{
    protected $signature = 'sheets:ingest';

    protected $description = 'Importa las filas nuevas de las hojas de Excel conectadas a la bandeja de revisión de su coordinación';

    public function handle(SheetInboxImporter $importer): int
    {
        $failed = false;

        $sources = SpreadsheetSource::query()
            ->where('active', true)
            ->whereNotNull('refresh_token')
            ->whereHas('coordination', fn ($query) => $query->active())
            ->with('coordination')
            ->orderBy('id')
            ->get();

        foreach ($sources as $source) {
            try {
                $result = $importer->import($source);
                $this->info($source->coordination->name.': '.__('sheet_sources.synced', $result));
            } catch (SheetInboxException $exception) {
                $this->error($source->coordination->name.': '.$exception->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
