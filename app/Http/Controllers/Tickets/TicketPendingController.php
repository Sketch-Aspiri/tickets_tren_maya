<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tickets;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\PendingTicketsRequest;
use App\Models\Ticket;
use App\Services\TicketListingService;
use Illuminate\View\View;

/**
 * "Mis pendientes": lo asignado al usuario que sigue abierto.
 */
class TicketPendingController extends Controller
{
    public function __construct(private readonly TicketListingService $listing) {}

    public function __invoke(PendingTicketsRequest $request): View
    {
        $this->authorize('viewAny', Ticket::class);

        return view('tickets.pending', ['tickets' => $this->listing->pendingFor($request->user())]);
    }
}
