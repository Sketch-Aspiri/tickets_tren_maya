<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Coordination;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

/**
 * Catalogo que el portal muestra al aprobar una cuenta (decision 99): roles y los equipos / coordinaciones donde
 * puede ubicarse a la persona. Solo lo activo; sin datos de usuarios.
 */
class PortalCatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $coordinations = Coordination::query()->with('station:id,name')->where('active', true)
            ->orderBy('name')->get(['id', 'station_id', 'name']);

        $teams = Team::query()->with('coordination.station:id,name')
            ->whereHas('coordination', fn ($q) => $q->where('active', true))
            ->orderBy('name')->get(['id', 'coordination_id', 'name']);

        return response()->json([
            'sistema' => 'tickets',
            'roles' => array_map(fn (UserRole $role): array => [
                'clave' => $role->value,
                'nombre' => $role->label(),
                'requiere_equipo' => $role->requiresTeam(),
                'requiere_coordinaciones' => $role === UserRole::JefeZona,
            ], UserRole::cases()),
            'equipos' => $teams->map(fn (Team $team): array => [
                'id' => $team->id,
                'nombre' => $team->name,
                'coordinacion_id' => $team->coordination_id,
                'coordinacion' => $team->coordination?->name,
                'estacion' => $team->coordination?->station?->name,
            ])->all(),
            'coordinaciones' => $coordinations->map(fn (Coordination $c): array => [
                'id' => $c->id,
                'nombre' => $c->name,
                'estacion' => $c->station?->name,
            ])->all(),
        ]);
    }
}
