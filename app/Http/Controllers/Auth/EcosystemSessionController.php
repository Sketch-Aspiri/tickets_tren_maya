<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\EcosystemSessionRequest;
use App\Services\Ecosystem\EcosystemSignature;
use App\Services\Ecosystem\SingleSignOnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Inicio y cierre de sesion unicos con el Dashboard (decision 88). Las tres rutas son GET porque llegan
 * por redireccion del navegador desde el otro sistema; ninguna confia en datos sin firma.
 */
class EcosystemSessionController extends Controller
{
    public function __construct(
        private readonly SingleSignOnService $sso,
        private readonly EcosystemSignature $signature,
    ) {}

    /**
     * El otro sistema pregunta si aqui hay sesion completa.
     */
    public function ask(EcosystemSessionRequest $request): RedirectResponse
    {
        $target = (string) $request->validated('para');
        $mfa = $this->sso->enabled() ? $this->sso->completeSession($request) : null;
        $user = $request->user();

        if ($mfa === null || $user === null) {
            return redirect()->away($this->sso->url($target, '/ecosistema/entrar?sin_sesion=1'));
        }

        // `linked`: el destino solo abre sesion sin contrasena si esta cuenta tambien esta vinculada.
        $code = $this->signature->sign($target, EcosystemSignature::ENTER, (string) $user->email, $mfa, $request->validated('estado'), $this->sso->isLinked($user));

        return redirect()->away($this->sso->url($target, '/ecosistema/entrar?codigo='.urlencode($code)));
    }

    /**
     * Respuesta del otro sistema: un codigo para entrar, o que alla no hay sesion.
     */
    public function enter(EcosystemSessionRequest $request): RedirectResponse
    {
        $this->sso->markAttempt($request);

        if ($request->user() !== null) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        $data = $this->signature->verify($request->validated('codigo'), EcosystemSignature::ENTER);

        // El codigo debe responder a la pregunta que hizo ESTE navegador.
        if ($data === null || ! $this->sso->validState($request, $data['state'])) {
            return redirect()->route('login');
        }

        $user = $this->sso->accountFor($data['email']);

        if ($user === null) {
            return redirect()->route('login')->with('status', __('auth.ecosystem.no_account', ['email' => $data['email']]));
        }

        // Sin contrasena solo si las DOS cuentas estan vinculadas: el correo del origen puede haberse
        // editado sin verificar y el de aqui puede ser de otra persona.
        if (! $this->sso->isLinked($user) || ! $data['lnk']) {
            $this->sso->requestLink($request, $data['email'], $data['iss']);

            return redirect()->route('login')->with('status', __('auth.ecosystem.link_first'));
        }

        $this->sso->signIn($request, $user, $data['mfa'], $data['iss']);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Se cerro la sesion en el otro sistema: se cierra aqui si es la misma persona y se vuelve a su login.
     */
    public function leave(EcosystemSessionRequest $request): RedirectResponse
    {
        $data = $this->signature->verify($request->validated('codigo'), EcosystemSignature::LEAVE);
        $user = $request->user();

        if ($data !== null && $user !== null && mb_strtolower((string) $user->email) === $data['email']) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $this->sso->markAttempt($request);

        $origin = $data['iss'] ?? $this->sso->sibling();

        return $origin !== null ? redirect()->away($this->sso->url($origin, '/login')) : redirect()->route('login');
    }
}
