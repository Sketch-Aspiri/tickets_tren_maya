<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tickets;

use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\DeleteTicketRequest;
use App\Http\Requests\Tickets\IndexTicketsRequest;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Requests\Tickets\UpdateTicketRequest;
use App\Models\Team;
use App\Models\Ticket;
use App\Services\AssignmentService;
use App\Services\CategoryService;
use App\Services\TicketListingService;
use App\Services\TicketService;
use App\Support\SupportDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TicketController extends Controller
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly TicketListingService $listing,
        private readonly CategoryService $categories,
        private readonly AssignmentService $assignments,
    ) {}

    public function index(IndexTicketsRequest $request): View
    {
        $this->authorize('viewAny', Ticket::class);

        $user = $request->user();

        return view('tickets.index', [
            'tickets' => $this->listing->paginate($user, $request->filters()),
            'filters' => $request->filters(),
            'statuses' => TicketStatus::cases(),
            'priorities' => Priority::cases(),
            'categories' => $this->categories->filterable($user),
            // Filtro por equipo: solo quien ve mas de un equipo (alcance global o varios equipos).
            'teams' => $user->selectableTeams(),
            'responsibles' => $this->assignments->assignableUsers($user),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Ticket::class);

        return view('tickets.create', $this->formData($request, new Ticket(['priority' => Priority::Medium])));
    }

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $this->authorize('create', Ticket::class);

        $ticket = $this->tickets->create($request->user(), $request->validated());

        return redirect()->route('tickets.show', $ticket)->with('status', 'ticket-created');
    }

    public function show(Request $request, Ticket $ticket): View
    {
        $this->authorize('view', $ticket);

        $user = $request->user();
        $ticket->load([
            'category:id,name',
            'team:id,name,coordination_id',
            'team.coordination:id,name,station_id',
            'team.coordination.station:id,name',
            'creator:id,name',
            'assignments' => fn ($query) => $query->orderBy('id'),
            'assignments.user:id,name',
            'statusHistories' => fn ($query) => $query->orderBy('id'),
            'statusHistories.user:id,name',
            'comments' => fn ($query) => $query->orderBy('id'),
            'comments.user:id,name',
            'attachments' => fn ($query) => $query->orderBy('id'),
            'attachments.user:id,name',
        ]);

        return view('tickets.show', [
            'ticket' => $ticket,
            'transitions' => $this->tickets->availableTransitions($user, $ticket),
            'assignableUsers' => $user->can('assign', $ticket) ? $this->assignments->assignableUsers($user, $ticket->team->coordination_id) : collect(),
        ]);
    }

    public function edit(Request $request, Ticket $ticket): View
    {
        $this->authorize('update', $ticket);

        return view('tickets.edit', $this->formData($request, $ticket));
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('update', $ticket);

        $this->tickets->update($ticket, $request->validated());

        return redirect()->route('tickets.show', $ticket)->with('status', 'ticket-updated');
    }

    public function destroy(DeleteTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('delete', $ticket);

        $this->tickets->delete($ticket);

        return redirect()->route('tickets.index')->with('status', 'ticket-deleted');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request, Ticket $ticket): array
    {
        $user = $request->user();

        return [
            'ticket' => $ticket,
            'priorities' => Priority::cases(),
            // Ticket nuevo: generales + las de sus coordinaciones (nunca se exponen las de otras); si lo manda a otra
            // coordinacion, la cascada deja solo las generales y CategoryFitsTeam lo exige. Al editar: la del equipo.
            'categories' => $ticket->exists
                ? $this->categories->selectable($user, $ticket->category_id, $ticket->team->coordination_id)
                : $this->categories->selectable($user, null, null, SupportDesk::coordinationId()),
            // Un ticket nuevo puede mandarse a cualquier equipo (p. ej. al de TI), no solo al del creador.
            'teams' => $ticket->exists ? collect() : Team::query()->with('coordination:id,name,station_id', 'coordination.station:id,name')->orderBy('name')->get(['id', 'name', 'coordination_id']),
            // `?coordination_id=` (p. ej. el acceso directo a Soporte del sistema) preselecciona esa coordinacion en
            // la cascada en vez del equipo propio; un id desconocido simplemente no preselecciona nada.
            'defaultCoordinationId' => $ticket->exists ? null : ($request->integer('coordination_id') ?: null),
            'defaultTeamId' => $ticket->exists || $request->integer('coordination_id') ? null : ($user->teamIds()[0] ?? null),
        ];
    }
}
