<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Ecosystem\EcosystemSignature;
use App\Services\Ecosystem\SingleSignOnService;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly SingleSignOnService $sso,
        private readonly EcosystemSignature $signature,
    ) {}

    /**
     * Antes de mostrar el login se le pregunta una vez al Dashboard si en este dispositivo ya hay sesion
     * (inicio de sesion unico, decision 88). Es una navegacion GET, no la limita `form-action`.
     */
    public function create(Request $request): View|RedirectResponse
    {
        if ($this->sso->shouldAsk($request)) {
            $this->sso->markAttempt($request);

            $state = $this->sso->newState($request);

            return redirect()->away($this->sso->url((string) $this->sso->sibling(), '/ecosistema/sso?para='.config('ecosistema.sistema').'&estado='.$state));
        }

        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Nueva sesion: evita fijacion de sesion y descarta cualquier verificacion 2FA previa.
        $request->session()->regenerate();
        $this->twoFactor->clearSessionVerification();

        // Si llego del Dashboard con este mismo correo, la cuenta queda vinculada y el inicio de sesion
        // unico ya no le pedira contrasena (decision 88).
        $this->sso->completeLink($request, $request->user());

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse|Response
    {
        $email = $request->user()?->email;

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Cierre unico: tambien se cierra la sesion de la misma persona en el Dashboard, que despues
        // regresa al login de aqui (sin volver a preguntar). Se navega con una pagina intermedia porque
        // la CSP `form-action 'self'` bloquea redirigir este POST a otro dominio.
        if ($email !== null && $this->sso->enabled()) {
            $this->sso->markAttempt($request);
            $sibling = (string) $this->sso->sibling();
            $code = $this->signature->sign($sibling, EcosystemSignature::LEAVE, (string) $email, false);

            return response()->view('auth.ecosystem-leave', [
                'url' => $this->sso->url($sibling, '/ecosistema/salir?codigo='.urlencode($code)),
            ])->header('Cache-Control', 'no-store');
        }

        return redirect()->route('login');
    }
}
