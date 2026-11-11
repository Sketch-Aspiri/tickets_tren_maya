<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Exceptions\EcosystemActivityException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\EcosystemActivityRequest;
use App\Services\Ecosystem\EcosystemActivityService;
use Illuminate\Http\JsonResponse;

/**
 * Actividades que pide el Dashboard (puente interno, decision 92). Responde el folio y la ruta de la actividad;
 * si el `ref` ya existia, la misma actividad con `creada: false` (200 en lugar de 201).
 */
class EcosystemActivityController extends Controller
{
    public function __invoke(EcosystemActivityRequest $request, EcosystemActivityService $activities): JsonResponse
    {
        try {
            $result = $activities->create(
                (string) $request->validated('evento'),
                $request->validated('estacion'),
                (string) $request->validated('ref'),
                (string) $request->validated('titulo'),
                (string) $request->validated('descripcion'),
                $request->normalizedUrl(),
            );
        } catch (EcosystemActivityException $e) {
            return response()->json(['error' => $e->reason], $e->reason === 'demasiadas' ? 429 : 422);
        }

        // El hecho ya tuvo una actividad que se elimino: no se recrea ni se entrega un folio que ya no abre.
        if ($result['deleted']) {
            return response()->json(['creada' => false, 'eliminada' => true]);
        }

        return response()->json([
            'folio' => $result['activity']->folio,
            'url' => route('activities.show', $result['activity'], false),
            'creada' => $result['created'],
        ], $result['created'] ? 201 : 200);
    }
}
