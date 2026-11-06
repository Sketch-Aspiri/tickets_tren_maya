<?php

declare(strict_types=1);

namespace App\Http\Controllers\Push;

use App\Http\Controllers\Controller;
use App\Http\Requests\Push\DestroyPushSubscriptionRequest;
use App\Http\Requests\Push\StorePushSubscriptionRequest;
use Illuminate\Http\JsonResponse;

/**
 * Alta y baja de suscripciones Web Push. Siempre operan sobre el usuario autenticado: no se puede
 * leer ni borrar la suscripción de otro (el borrado filtra por dueño).
 */
class PushSubscriptionController extends Controller
{
    private const MAX_DEVICES_PER_USER = 10;

    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        $user = $request->user();
        $endpoint = $request->validated('endpoint');

        $isKnown = $user->pushSubscriptions()->where('endpoint', $endpoint)->exists();

        if (! $isKnown && $user->pushSubscriptions()->count() >= self::MAX_DEVICES_PER_USER) {
            return response()->json(['success' => false, 'message' => __('push.errors.too_many')], 422);
        }

        $user->updatePushSubscription(
            $endpoint,
            $request->validated('keys.p256dh'),
            $request->validated('keys.auth'),
            $request->validated('contentEncoding'),
        );

        return response()->json(['success' => true], $isKnown ? 200 : 201);
    }

    public function destroy(DestroyPushSubscriptionRequest $request): JsonResponse
    {
        $request->user()->deletePushSubscription($request->validated('endpoint'));

        return response()->json(['success' => true]);
    }
}
