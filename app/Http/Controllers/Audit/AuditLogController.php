<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\IndexAuditLogRequest;
use App\Services\AuditLogService;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;

/**
 * Visor de la bitácora de auditoría (solo jefe de zona; solo lectura). No existe ninguna ruta que escriba
 * o borre entradas desde la aplicación.
 */
class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(IndexAuditLogRequest $request): View
    {
        $this->authorize('viewAny', ActivityLogEntry::class);

        $entries = $this->audit->paginate($request->filters());

        return view('audit.index', [
            'entries' => $entries,
            'rows' => $entries->getCollection()->map(fn (ActivityLogEntry $entry): array => $this->audit->present($entry)),
            'filters' => $request->filters(),
            'events' => $this->audit->events(),
            'causers' => $this->audit->causers(),
            'subjectTypes' => array_keys(AuditLogService::SUBJECT_TYPES),
        ]);
    }
}
