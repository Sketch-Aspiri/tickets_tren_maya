<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\EcosystemTeamRequest;
use App\Services\Ecosystem\StationTeamService;
use Illuminate\Http\JsonResponse;

/**
 * Equipos del Dashboard (puente interno): crea el equipo central y los subequipos de estacion en Tickets.
 */
class EcosystemTeamController extends Controller
{
    public function __invoke(EcosystemTeamRequest $request, StationTeamService $teams): JsonResponse
    {
        return response()->json($teams->ensure(
            (string) $request->validated('clave'),
            (string) $request->validated('nombre'),
            array_values((array) $request->validated('estaciones')),
        ));
    }
}
