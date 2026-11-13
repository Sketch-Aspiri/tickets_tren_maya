<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\PortalAccountRequest;
use App\Models\User;
use App\Services\Ecosystem\PortalProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cuentas para el portal (puente interno, decision 99): alta de la cuenta aprobada y, para la simulacion de
 * emparejado, un listado sin datos sensibles (nunca contrasenas, 2FA ni tokens).
 */
class PortalAccountController extends Controller
{
    public function store(PortalAccountRequest $request, PortalProvisioningService $provisioning): JsonResponse
    {
        $result = $provisioning->provision($request->validated());

        // 409: ya hay una cuenta con ese correo que no es de este alta; el portal lo muestra como conflicto.
        return response()->json($result, $result['estado'] === PortalProvisioningService::EMAIL_TAKEN ? 409 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $after = max(0, (int) $request->query('despues', 0));
        $limit = min(500, max(1, (int) $request->query('limite', 500)));

        $users = User::query()->with('roles:id,name')
            ->where('id', '>', $after)
            ->where('email', 'not like', '%.invalid')
            ->orderBy('id')->limit($limit + 1)
            ->get(['id', 'email', 'name', 'status', 'sso_linked_at', 'portal_user_id']);

        $page = $users->take($limit);

        return response()->json([
            'cuentas' => $page->map(fn (User $user): array => [
                'email' => mb_strtolower((string) $user->email),
                'nombre' => $user->name,
                'estado' => $user->status->value,
                'rol' => $user->roles->first()?->name,
                'vinculada' => $user->sso_linked_at !== null,
                'emparejada' => $user->portal_user_id !== null,
            ])->values()->all(),
            'siguiente' => $users->count() > $limit ? $page->last()->id : null,
        ]);
    }
}
