<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tickets;

use App\Enums\PendingScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\PendingTicketsRequest;
use App\Models\Ticket;
use App\Services\ActivityListingService;
use App\Services\TicketListingService;
use Illuminate\View\View;

/**
 * "Mis pendientes": lo asignado al usuario que sigue abierto, en dos secciones (tickets y actividades) con
 * paginación independiente. Toda actividad asignada a alguien tiene ya alcance de lectura (ActivityPolicy::view).
 * Los coordinadores pueden alternar (`?scope=team`) a lo abierto de su equipo.
 */
class TicketPendingController extends Controller
{
    public function __construct(
        private readonly TicketListingService $tickets,
        private readonly ActivityListingService $activities,
    ) {}

    public function __invoke(PendingTicketsRequest $request): View
    {
        $this->authorize('viewAny', Ticket::class);

        $user = $request->user();
        $scope = $request->pendingScope();

        return view('tickets.pending', [
            'tickets' => $this->tickets->pendingFor($user, $scope),
            'activities' => $this->activities->pendingFor($user, $scope),
            'scope' => $scope,
            'scopes' => PendingScope::canUseTeam($user) ? PendingScope::cases() : [],
        ]);
    }
}
