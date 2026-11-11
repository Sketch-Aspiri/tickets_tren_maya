<?php

declare(strict_types=1);

namespace App\Services\Ecosystem;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Inicio de sesion unico con el Dashboard en un mismo dispositivo (decision 88), sin cookies compartidas
 * entre dominios. Igual que App\Services\Ecosistema\InicioUnicoService del Dashboard:
 *
 * 1. Sin sesion, el login de un sistema redirige (una vez) al otro: GET /ecosistema/sso?para=<este>.
 * 2. Si alla hay una sesion completa (2FA incluido cuando aplica), responde con un codigo firmado de un
 *    solo uso (EcosystemSignature) a /ecosistema/entrar; si no, con ?sin_sesion=1 y se muestra el login.
 * 3. El destino busca la cuenta activa con rol y el mismo correo y abre la sesion; si el origen verifico
 *    el 2FA y la cuenta destino lo tiene configurado, no se vuelve a pedir.
 *
 * Seguridad:
 * - Cada pregunta lleva un `estado` aleatorio guardado en la sesion de quien pregunta; el codigo de
 *   respuesta lo trae firmado y solo se acepta en ese mismo navegador (evita el login CSRF).
 * - El correo no basta: la cuenta debe estar vinculada (`sso_linked_at`). La primera vez se pide la
 *   contrasena de aqui una sola vez; al iniciar sesion con el correo que llego firmado, queda vinculada.
 *
 * Cerrar sesion en uno cierra la del otro (codigo firmado de "salir").
 */
final class SingleSignOnService
{
    public const SESSION_ATTEMPT = 'ecosistema.sso_intentado';

    public const SESSION_STATE = 'ecosistema.sso_estado';

    public const SESSION_LINK = 'ecosistema.sso_vinculo';

    public function __construct(
        private readonly EcosystemSignature $signature,
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    public function enabled(): bool
    {
        return $this->signature->configured() && $this->sibling() !== null;
    }

    public function sibling(): ?string
    {
        return collect(array_keys((array) config('ecosistema.urls')))
            ->reject(fn (string $key): bool => $key === config('ecosistema.sistema'))
            ->first();
    }

    public function url(string $system, string $path): string
    {
        return config('ecosistema.urls.'.$system).$path;
    }

    /**
     * Una vez cada pocos minutos por sesion, y solo si el otro sistema responde (para no dejar a nadie en
     * una pagina de error si esta caido).
     */
    public function shouldAsk(Request $request): bool
    {
        if (! $this->enabled() || $request->boolean('local')) {
            return false;
        }

        $last = (int) $request->session()->get(self::SESSION_ATTEMPT, 0);

        if ($last > now()->subMinutes((int) config('ecosistema.sso.reintento_minutos', 5))->getTimestamp()) {
            return false;
        }

        return $this->reachable((string) $this->sibling());
    }

    public function markAttempt(Request $request): void
    {
        $request->session()->put(self::SESSION_ATTEMPT, now()->getTimestamp());
    }

    /**
     * Valor aleatorio que viaja en la pregunta y vuelve firmado en la respuesta.
     */
    public function newState(Request $request): string
    {
        $state = Str::random(40);
        $request->session()->put(self::SESSION_STATE, $state);

        return $state;
    }

    public function validState(Request $request, ?string $state): bool
    {
        $expected = $request->session()->pull(self::SESSION_STATE);

        return is_string($expected) && is_string($state) && hash_equals($expected, $state);
    }

    public function isLinked(User $user): bool
    {
        return $user->sso_linked_at !== null;
    }

    /**
     * Recuerda (10 min) que esta persona llego del otro sistema con este correo; si ahora inicia sesion
     * aqui con contrasena, la cuenta se vincula.
     */
    public function requestLink(Request $request, string $email, string $origin): void
    {
        $request->session()->put(self::SESSION_LINK, [
            'email' => mb_strtolower($email),
            'origin' => $origin,
            'exp' => now()->addMinutes(10)->getTimestamp(),
        ]);
    }

    public function completeLink(Request $request, User $user): void
    {
        $pending = $request->session()->pull(self::SESSION_LINK);

        if (! is_array($pending)
            || ($pending['exp'] ?? 0) < now()->getTimestamp()
            || ($pending['email'] ?? null) !== mb_strtolower((string) $user->email)
            || $this->isLinked($user)) {
            return;
        }

        $user->forceFill(['sso_linked_at' => now()])->save();

        $this->audit->record('auth', 'sso_linked', $user, $user, extra: [
            'origin' => $pending['origin'] ?? null,
            'ip' => $request->ip(),
        ]);
    }

    /**
     * Si la sesion actual esta completa devuelve si paso el 2FA; si no (sin usuario, cuenta no activa,
     * sin rol, 2FA pendiente o sin configurar cuando es obligatorio), null: no se comparte.
     */
    public function completeSession(Request $request): ?bool
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isActive() || $user->roleEnum() === null) {
            return null;
        }

        if ($user->hasTwoFactorEnabled()) {
            return $this->twoFactor->isVerifiedInSession($user) ? true : null;
        }

        return $user->requiresTwoFactor() ? null : false;
    }

    public function accountFor(string $email): ?User
    {
        $user = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();

        return $user !== null && $user->isActive() && $user->roleEnum() !== null ? $user : null;
    }

    public function signIn(Request $request, User $user, bool $mfa, string $origin): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->twoFactor->clearSessionVerification();

        $inherited = $mfa && $user->hasTwoFactorEnabled();

        if ($inherited) {
            $this->twoFactor->markVerifiedInSession($user);
        }

        $this->audit->record('auth', 'sso_login', $user, $user, extra: [
            'origin' => $origin,
            'two_factor_inherited' => $inherited,
            'ip' => $request->ip(),
        ]);
    }

    private function reachable(string $system): bool
    {
        return Cache::remember('ecosistema:disponible:'.$system, 60, function () use ($system): bool {
            try {
                return Http::timeout(2)->connectTimeout(2)->get($this->url($system, '/up'))->successful();
            } catch (Throwable) {
                return false;
            }
        });
    }
}
