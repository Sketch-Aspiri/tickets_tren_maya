<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ActsAsMcpClient;
use App\Models\Team;
use App\Services\Mcp\EmailActivityService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lista los equipos con los que puedes trabajar (id, nombre, coordinacion y edificio). Los necesitas para proponer una actividad.')]
#[IsReadOnly]
#[Name('listar_equipos')]
class ListTeamsTool extends Tool
{
    use ActsAsMcpClient;

    public function __construct(private readonly EmailActivityService $service) {}

    public function handle(Request $request): Response
    {
        return $this->run(function ($client): Response {
            $actor = $this->service->actor($client);

            $teams = Team::query()
                ->when(! $actor->seesAllTeams(), fn ($query) => $query->whereIn('id', $actor->workingTeamIds()))
                ->with('coordination:id,name,station_id', 'coordination.station:id,name')
                ->orderBy('coordination_id')
                ->orderBy('name')
                ->limit(300)
                ->get();

            return Response::json(['equipos' => $teams->map(fn (Team $team): array => [
                'id' => $team->getKey(),
                'nombre' => $team->name,
                'coordinacion_id' => $team->coordination_id,
                'coordinacion' => $team->coordination?->name,
                'edificio' => $team->coordination?->station?->name,
            ])->all()]);
        });
    }
}
