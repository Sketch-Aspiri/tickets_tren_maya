<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\PortalAvatarRequest;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Http\JsonResponse;

/**
 * Foto de perfil unica (puente con el portal, decision 98). El portal la lee (herencia de las fotos que ya habia aqui)
 * y la reparte cuando cambia en cualquier sistema. Lo que llega por aqui NO se vuelve a avisar al portal (sin eco).
 */
class PortalAvatarController extends Controller
{
    public function show(PortalAvatarRequest $request, AvatarService $avatars): JsonResponse
    {
        $user = $this->account((string) $request->validated('email'));

        if ($user === null) {
            return response()->json(['estado' => 'sin_cuenta'], 404);
        }

        $bytes = $avatars->bytes($user);

        return response()->json(['imagen' => $bytes === null ? null : base64_encode($bytes)]);
    }

    public function update(PortalAvatarRequest $request, AvatarService $avatars): JsonResponse
    {
        $data = $request->validated();
        $user = $this->account($data['email']);

        if ($user === null) {
            return response()->json(['estado' => 'sin_cuenta'], 404);
        }

        $bytes = base64_decode($data['imagen'], true);

        if ($bytes === false) {
            return response()->json(['error' => 'invalid'], 422);
        }

        try {
            $avatars->setFromBytes($user, $bytes, propagate: false);
        } catch (BusinessRuleException) {
            return response()->json(['error' => 'invalid_image'], 422);
        }

        return response()->json(['estado' => 'ok']);
    }

    public function destroy(PortalAvatarRequest $request, AvatarService $avatars): JsonResponse
    {
        $user = $this->account((string) $request->validated('email'));

        if ($user === null) {
            return response()->json(['estado' => 'sin_cuenta'], 404);
        }

        $avatars->remove($user, propagate: false);

        return response()->json(['estado' => 'ok']);
    }

    private function account(string $email): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();
    }
}
