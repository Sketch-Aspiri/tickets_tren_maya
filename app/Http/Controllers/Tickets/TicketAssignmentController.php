<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tickets;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\AssignTicketRequest;
use App\Http\Requests\Tickets\TakeTicketRequest;
use App\Http\Requests\Tickets\UnassignTicketRequest;
use App\Models\Ticket;
use App\Services\AssignmentService;
use Illuminate\Http\RedirectResponse;

/**
 * Asignar / reasignar / delegar, devolver a la bolsa y "tomar" un ticket de la bolsa.
 */
class TicketAssignmentController extends Controller
{
    public function __construct(private readonly AssignmentService $assignments) {}

    public function update(AssignTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('assign', $ticket);

        $this->assignments->assign(
            $request->user(),
            $ticket,
            (int) $request->validated('responsible_id'),
            $request->collaboratorIds(),
        );

        return redirect()->route('tickets.show', $ticket)->with('status', 'ticket-assigned');
    }

    public function destroy(UnassignTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('assign', $ticket);

        $this->assignments->unassign($request->user(), $ticket);

        return redirect()->route('tickets.show', $ticket)->with('status', 'ticket-unassigned');
    }

    public function take(TakeTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('take', $ticket);

        $this->assignments->take($request->user(), $ticket);

        return redirect()->route('tickets.show', $ticket)->with('status', 'ticket-taken');
    }
}
