<?php

declare(strict_types=1);

namespace App\Http\Controllers\Activities;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exports\ExportActivitiesRequest;
use App\Models\Activity;
use App\Services\ActivityExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Descarga del listado filtrado de actividades en Excel (jefe y coordinador, dentro de su alcance).
 */
class ActivityExportController extends Controller
{
    public function __construct(private readonly ActivityExportService $export) {}

    public function __invoke(ExportActivitiesRequest $request): BinaryFileResponse
    {
        $this->authorize('export', Activity::class);

        return $this->export->download($request->user(), $request->filters(), $request->exportFormat());
    }
}
