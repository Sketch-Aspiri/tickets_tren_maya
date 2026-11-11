<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\PendingSummaryRequest;
use App\Services\Ecosystem\PendingSummaryService;
use Illuminate\Http\JsonResponse;

/**
 * Resumen de "Mis pendientes" para el Dashboard (puente interno, decision 89).
 */
class PendingSummaryController extends Controller
{
    public function __invoke(PendingSummaryRequest $request, PendingSummaryService $summary): JsonResponse
    {
        return response()->json($summary->for((string) $request->validated('correo')));
    }
}
