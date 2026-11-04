<?php

declare(strict_types=1);

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Http\Requests\Org\DeleteStationRequest;
use App\Http\Requests\Org\StoreStationRequest;
use App\Http\Requests\Org\UpdateStationRequest;
use App\Models\Station;
use App\Models\User;
use App\Services\StationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class StationController extends Controller
{
    private const USER_RELATIONS = ['roles:id,name', 'teams:id,name,coordination_id', 'teams.coordination:id,name,station_id', 'teams.coordination.station:id,name', 'coordinations:id,name,station_id', 'coordinations.station:id,name'];

    public function __construct(private readonly StationService $stations) {}

    public function index(): View
    {
        $this->authorize('viewAny', Station::class);

        return view('org.stations.index', ['stations' => $this->stations->paginate()]);
    }

    public function show(Station $station): View
    {
        $this->authorize('view', $station);

        $users = User::query()
            ->with(self::USER_RELATIONS)
            ->where(fn ($q) => $q
                ->whereHas('teams.coordination', fn ($c) => $c->where('station_id', $station->getKey()))
                ->orWhereHas('coordinations', fn ($c) => $c->where('coordinations.station_id', $station->getKey())))
            ->orderBy('name')
            ->paginate((int) config('tickets.users_per_page'));

        return view('org.stations.show', [
            'station' => $station->load(['coordinations' => fn ($q) => $q->withCount('teams')->orderBy('name')]),
            'users' => $users,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Station::class);

        return view('org.stations.create', ['station' => new Station(['active' => true])]);
    }

    public function store(StoreStationRequest $request): RedirectResponse
    {
        $this->stations->create($request->payload());

        return redirect()->route('stations.index')->with('status', 'station-created');
    }

    public function edit(Station $station): View
    {
        $this->authorize('update', $station);

        return view('org.stations.edit', ['station' => $station]);
    }

    public function update(UpdateStationRequest $request, Station $station): RedirectResponse
    {
        $this->stations->update($station, $request->payload());

        return redirect()->route('stations.index')->with('status', 'station-updated');
    }

    public function destroy(DeleteStationRequest $request, Station $station): RedirectResponse
    {
        $this->stations->delete($station);

        return redirect()->route('stations.index')->with('status', 'station-deleted');
    }
}
