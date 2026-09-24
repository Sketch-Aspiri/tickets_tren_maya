<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExportFormat;
use App\Exports\ActivitiesExport;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Exporta a Excel el MISMO listado (alcance por rol + filtros) que ve el usuario en /activities, con lo mismo que
 * muestra el listado (plantillas incluidas salvo que el filtro `kind` las excluya). El tope de filas, el nombre
 * del archivo y la bitacora los resuelve ExportDownloader.
 */
final class ActivityExportService
{
    public function __construct(
        private readonly ActivityListingService $listing,
        private readonly ExportDownloader $downloader,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  Filtros ya validados (IndexActivitiesRequest).
     */
    public function download(User $actor, array $filters, ExportFormat $format): BinaryFileResponse
    {
        return $this->downloader->download(
            $actor,
            'activities',
            $filters,
            $format,
            fn (int $limit): Collection => $this->listing->limited($actor, $filters, $limit),
            fn (Collection $rows): ActivitiesExport => new ActivitiesExport($rows),
        );
    }
}
