<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tickets;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exports\ExportTicketsRequest;
use App\Models\Ticket;
use App\Services\TicketExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Descarga del listado filtrado de tickets en Excel (jefe y coordinador, dentro de su alcance).
 */
class TicketExportController extends Controller
{
    public function __construct(private readonly TicketExportService $export) {}

    public function __invoke(ExportTicketsRequest $request): BinaryFileResponse
    {
        $this->authorize('export', Ticket::class);

        return $this->export->download($request->user(), $request->filters(), $request->exportFormat());
    }
}
