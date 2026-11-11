<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\EcosystemNoticeRequest;
use App\Services\Ecosystem\EcosystemNoticeService;
use Illuminate\Http\JsonResponse;

/**
 * Avisos del Dashboard (puente interno, decision 91): campana + Web Push para las cuentas vinculadas.
 */
class EcosystemNoticeController extends Controller
{
    public function __invoke(EcosystemNoticeRequest $request, EcosystemNoticeService $notices): JsonResponse
    {
        $recipients = $notices->send(
            (string) $request->attributes->get('bridge_system'),
            array_values($request->validated('correos')),
            (string) $request->validated('titulo'),
            (string) $request->validated('cuerpo'),
            $request->normalizedUrl(),
            $request->validated('ref'),
        );

        return response()->json(['recipients' => $recipients]);
    }
}
