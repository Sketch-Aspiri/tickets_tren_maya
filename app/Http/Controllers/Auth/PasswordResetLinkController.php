<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Respuesta uniforme exista o no el correo: no revela que cuentas estan registradas.
     */
    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        Password::sendResetLink($request->safe()->only('email'));

        return back()->with('status', __('passwords.sent'));
    }
}
