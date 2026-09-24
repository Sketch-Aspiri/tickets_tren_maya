<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExportFormat;
use App\Exports\TicketsExport;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Exporta a Excel el MISMO listado (alcance por rol + filtros) que ve el usuario en /tickets. El tope de filas, el
 * nombre del archivo y la bitacora los resuelve ExportDownloader.
 */
final class TicketExportService
{
    public function __construct(
        private readonly TicketListingService $listing,
        private readonly ExportDownloader $downloader,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  Filtros ya validados (IndexTicketsRequest).
     */
    public function download(User $actor, array $filters, ExportFormat $format): BinaryFileResponse
    {
        return $this->downloader->download(
            $actor,
            'tickets',
            $filters,
            $format,
            fn (int $limit): Collection => $this->listing->limited($actor, $filters, $limit),
            fn (Collection $rows): TicketsExport => new TicketsExport($rows),
        );
    }
}
