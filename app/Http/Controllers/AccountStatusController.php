<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pantalla para cuentas sin acceso (pendiente, inactiva o sin rol). Es la unica
 * ruta autenticada, junto al logout, que no exige una cuenta activa.
 */
class AccountStatusController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->canAccessApplication()) {
            return redirect()->route('dashboard');
        }

        $stateKey = match (true) {
            $user->isInactive() => 'inactive',
            $user->isPending() => 'pending',
            default => 'no_role',
        };

        return view('account.status', ['user' => $user, 'stateKey' => $stateKey]);
    }
}
