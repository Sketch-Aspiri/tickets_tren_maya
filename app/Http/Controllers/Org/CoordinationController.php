<?php

declare(strict_types=1);

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Http\Requests\Org\DeleteCoordinationRequest;
use App\Http\Requests\Org\StoreCoordinationRequest;
use App\Http\Requests\Org\UpdateCoordinationRequest;
use App\Models\Coordination;
use App\Models\Station;
use App\Models\User;
use App\Services\CoordinationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CoordinationController extends Controller
{
    private const USER_RELATIONS = ['roles:id,name', 'teams:id,name,coordination_id', 'teams.coordination:id,name,station_id', 'teams.coordination.station:id,name', 'coordinations:id,name,station_id', 'coordinations.station:id,name'];

    public function __construct(private readonly CoordinationService $coordinations) {}

    public function index(): View
    {
        $this->authorize('viewAny', Coordination::class);

        return view('org.coordinations.index', ['coordinations' => $this->coordinations->paginate()]);
    }

    public function show(Coordination $coordination): View
    {
        $this->authorize('view', $coordination);

        $users = User::query()
            ->with(self::USER_RELATIONS)
            ->where(fn ($q) => $q
                ->whereHas('teams', fn ($t) => $t->where('teams.coordination_id', $coordination->getKey()))
                ->orWhereHas('coordinations', fn ($c) => $c->where('coordinations.id', $coordination->getKey())))
            ->orderBy('name')
            ->paginate((int) config('tickets.users_per_page'));

        return view('org.coordinations.show', [
            'coordination' => $coordination->load(['station', 'teams' => fn ($q) => $q->withCount('members')->with('coordinator:id,name')->orderBy('name')]),
            'users' => $users,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Coordination::class);

        return view('org.coordinations.create', [
            'coordination' => new Coordination(['active' => true]),
            'stations' => $this->stationOptions(),
        ]);
    }

    public function store(StoreCoordinationRequest $request): RedirectResponse
    {
        $this->coordinations->create($request->payload());

        return redirect()->route('coordinations.index')->with('status', 'coordination-created');
    }

    public function edit(Coordination $coordination): View
    {
        $this->authorize('update', $coordination);

        return view('org.coordinations.edit', [
            'coordination' => $coordination,
            'stations' => $this->stationOptions(),
        ]);
    }

    public function update(UpdateCoordinationRequest $request, Coordination $coordination): RedirectResponse
    {
        $this->coordinations->update($coordination, $request->payload());

        return redirect()->route('coordinations.index')->with('status', 'coordination-updated');
    }

    public function destroy(DeleteCoordinationRequest $request, Coordination $coordination): RedirectResponse
    {
        $this->coordinations->delete($coordination);

        return redirect()->route('coordinations.index')->with('status', 'coordination-deleted');
    }

    /**
     * @return Collection<int, Station>
     */
    private function stationOptions(): Collection
    {
        return Station::query()->orderBy('name')->get(['id', 'name']);
    }
}
