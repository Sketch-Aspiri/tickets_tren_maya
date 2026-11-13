<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\DeleteTeamRequest;
use App\Http\Requests\Teams\StoreTeamRequest;
use App\Http\Requests\Teams\UpdateTeamRequest;
use App\Models\Coordination;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function __construct(private readonly TeamService $teams) {}

    public function index(): View
    {
        $this->authorize('viewAny', Team::class);

        return view('teams.index', ['teams' => $this->teams->paginate(request()->user())]);
    }

    public function show(Team $team): View
    {
        $this->authorize('view', $team);

        $users = $team->members()
            ->with(['roles:id,name', 'teams:id,name,coordination_id', 'teams.coordination:id,name,station_id', 'teams.coordination.station:id,name', 'coordinations:id,name,station_id', 'coordinations.station:id,name'])
            ->orderBy('name')
            ->paginate((int) config('tickets.users_per_page'));

        return view('teams.show', [
            'team' => $team->load(['coordination.station', 'coordinator:id,name', 'additionalCoordinators:id,name']),
            'users' => $users,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Team::class);

        $team = new Team;

        return view('teams.create', [
            'team' => $team,
            'coordinators' => $this->coordinatorOptions($team),
            'coordinations' => $this->coordinationOptions(),
        ]);
    }

    public function store(StoreTeamRequest $request): RedirectResponse
    {
        $this->authorize('create', Team::class);

        $this->teams->create($request->validated());

        return redirect()->route('teams.index')->with('status', 'team-created');
    }

    public function edit(Team $team): View
    {
        $this->authorize('update', $team);

        return view('teams.edit', [
            'team' => $team->load('additionalCoordinators:id,name'),
            'coordinators' => $this->coordinatorOptions($team),
            'coordinations' => $this->coordinationOptions($team->coordination_id),
        ]);
    }

    public function update(UpdateTeamRequest $request, Team $team): RedirectResponse
    {
        $this->authorize('update', $team);

        $this->teams->update($team, $request->validated());

        return redirect()->route('teams.index')->with('status', 'team-updated');
    }

    public function destroy(DeleteTeamRequest $request, Team $team): RedirectResponse
    {
        $this->authorize('delete', $team);

        $this->teams->delete($team);

        return redirect()->route('teams.index')->with('status', 'team-deleted');
    }

    /**
     * Coordinaciones activas (mas la actual del equipo aunque este inactiva) para el selector.
     *
     * @return Collection<int, Coordination>
     */
    private function coordinationOptions(?int $keepId = null): Collection
    {
        $actor = request()->user();

        return Coordination::query()
            ->with('station:id,name')
            ->when(! $actor->seesAllTeams(), fn ($query) => $query->whereIn('id', $actor->coordinationIdsQuery()))
            ->where(fn ($query) => $query->where('active', true)->when($keepId, fn ($inner, int $id) => $inner->orWhere('id', $id)))
            ->orderBy('name')
            ->get();
    }

    /**
     * Solo pueden coordinar un equipo sus propios integrantes; un equipo nuevo aun no tiene ninguno.
     *
     * @return Collection<int, User>
     */
    private function coordinatorOptions(Team $team): Collection
    {
        if (! $team->exists) {
            return new Collection;
        }

        return User::query()
            ->activeWithRole(UserRole::Coordinador)
            ->memberOfAny([$team->getKey()])
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
