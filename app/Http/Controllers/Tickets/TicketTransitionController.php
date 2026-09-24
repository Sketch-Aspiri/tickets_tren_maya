<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tickets;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\TransitionTicketRequest;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;

class TicketTransitionController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    public function store(TransitionTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $target = $request->target();
        $this->authorize('transition', [$ticket, $target]);

        $this->tickets->transition($request->user(), $ticket, $target, $request->comment());

        return redirect()->route('tickets.show', $ticket)->with('status', 'ticket-status-changed');
    }
}
