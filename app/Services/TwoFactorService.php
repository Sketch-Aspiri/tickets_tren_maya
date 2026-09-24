<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * 2FA TOTP (pragmarx/google2fa). El secreto se guarda cifrado (cast `encrypted`),
 * los codigos de recuperacion hasheados y cada codigo TOTP solo puede usarse una vez.
 */
final class TwoFactorService
{
    /** Clave de sesion: id del usuario que ya supero el desafio en esta sesion. */
    public const SESSION_KEY = 'two_factor.verified_user_id';

    private const LOG = 'auth';

    private const SECRET_LENGTH = 32;

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly AuditLogger $audit,
        private readonly SessionInvalidator $sessions,
    ) {}

    public function isVerifiedInSession(User $user): bool
    {
        return session(self::SESSION_KEY) === $user->getKey();
    }

    public function markVerifiedInSession(User $user): void
    {
        session()->regenerate();
        session()->put(self::SESSION_KEY, $user->getKey());
    }

    public function clearSessionVerification(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Genera (o reutiliza mientras no se confirme) el secreto y devuelve lo necesario para mostrarlo.
     *
     * @return array{secret: string, qr_svg: string}
     */
    public function prepareSetup(User $user): array
    {
        $secret = $this->withLockedUser($user, function (User $locked): string {
            if ($locked->hasTwoFactorEnabled()) {
                throw BusinessRuleException::because('two_factor.errors.already_enabled');
            }

            if ($locked->two_factor_secret === null) {
                $locked->forceFill(['two_factor_secret' => $this->google2fa->generateSecretKey(self::SECRET_LENGTH)])->save();
            }

            return $locked->two_factor_secret;
        });

        $uri = $this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret);

        return ['secret' => $secret, 'qr_svg' => $this->renderQrSvg($uri)];
    }

    /**
     * Confirma la configuracion con un codigo valido y devuelve los codigos de recuperacion (una sola vez).
     *
     * @return list<string>|null null si el codigo es incorrecto
     */
    public function confirmSetup(User $user, string $code): ?array
    {
        return $this->withLockedUser($user, function (User $locked) use ($code): ?array {
            if ($locked->two_factor_secret === null || $locked->hasTwoFactorEnabled()) {
                throw BusinessRuleException::because('two_factor.errors.no_pending_setup');
            }

            $timestamp = $this->verifyCode($locked, $code);

            if ($timestamp === null) {
                $this->audit->record(self::LOG, 'two_factor_setup_failed', $locked, $locked);

                return null;
            }

            $plainCodes = $this->generateRecoveryCodes();

            $locked->forceFill([
                'two_factor_confirmed_at' => now(),
                'two_factor_last_timestamp' => $timestamp,
                'two_factor_recovery_codes' => $this->hashCodes($plainCodes),
            ])->save();

            $this->audit->record(self::LOG, 'two_factor_enabled', $locked, $locked);

            return $plainCodes;
        });
    }

    /**
     * Valida el codigo TOTP del desafio de inicio de sesion (rechaza codigos ya usados).
     * Verifica y consume bajo bloqueo de fila: dos peticiones simultaneas con el mismo codigo
     * no pueden ganar ambas.
     */
    public function passesChallenge(User $user, string $code): bool
    {
        return $this->withLockedUser($user, function (User $locked) use ($code): bool {
            if (! $locked->hasTwoFactorEnabled()) {
                return false;
            }

            $timestamp = $this->verifyCode($locked, $code);

            if ($timestamp === null) {
                $this->audit->record(self::LOG, 'two_factor_challenge_failed', $locked, $locked);

                return false;
            }

            $locked->forceFill(['two_factor_last_timestamp' => $timestamp])->save();
            $this->audit->record(self::LOG, 'two_factor_challenge_passed', $locked, $locked);

            return true;
        });
    }

    /**
     * Consume un codigo de recuperacion (uso unico), bajo bloqueo de fila.
     */
    public function passesRecoveryChallenge(User $user, string $code): bool
    {
        $normalized = $this->normalizeRecoveryCode($code);

        return $this->withLockedUser($user, function (User $locked) use ($normalized): bool {
            $hashes = $locked->two_factor_recovery_codes ?? [];

            foreach ($hashes as $index => $hash) {
                if (Hash::check($normalized, $hash)) {
                    unset($hashes[$index]);
                    $locked->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();
                    $this->audit->record(self::LOG, 'two_factor_recovery_used', $locked, $locked, extra: ['remaining' => count($hashes)]);

                    return true;
                }
            }

            $this->audit->record(self::LOG, 'two_factor_challenge_failed', $locked, $locked, extra: ['method' => 'recovery_code']);

            return false;
        });
    }

    /**
     * @return list<string> nuevos codigos en claro (mostrar una sola vez)
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        return $this->withLockedUser($user, function (User $locked): array {
            if (! $locked->hasTwoFactorEnabled()) {
                throw BusinessRuleException::because('two_factor.errors.not_enabled');
            }

            $plainCodes = $this->generateRecoveryCodes();
            $locked->forceFill(['two_factor_recovery_codes' => $this->hashCodes($plainCodes)])->save();
            $this->audit->record(self::LOG, 'two_factor_recovery_regenerated', $locked, $locked);

            return $plainCodes;
        });
    }

    /**
     * Los roles con 2FA obligatorio no pueden desactivarlo.
     */
    public function disable(User $user): void
    {
        if ($user->requiresTwoFactor()) {
            throw BusinessRuleException::because('two_factor.errors.required_for_role');
        }

        $this->withLockedUser($user, function (User $locked): void {
            $this->clearTwoFactorColumns($locked);
            $this->audit->record(self::LOG, 'two_factor_disabled', $locked, $locked);
        });

        $this->clearSessionVerification();
    }

    /**
     * Restablecimiento por consola (perdio el dispositivo y los codigos de recuperacion): borra
     * secreto, marca de tiempo y codigos, y cierra todas sus sesiones. Sin secretos en la bitacora.
     *
     * @return bool false si el usuario no tenia nada que restablecer
     */
    public function reset(User $user): bool
    {
        return $this->withLockedUser($user, function (User $locked): bool {
            $hasData = $locked->two_factor_secret !== null
                || $locked->two_factor_confirmed_at !== null
                || $locked->two_factor_recovery_codes !== null;

            if (! $hasData) {
                return false;
            }

            $this->clearTwoFactorColumns($locked);
            $this->sessions->revokeAll($locked);
            $this->audit->record(self::LOG, 'two_factor_reset_by_console', $locked);

            return true;
        });
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    /**
     * Ejecuta la operacion en una transaccion sobre la fila del usuario bloqueada (`FOR UPDATE`),
     * con el estado mas reciente de la BD, y deja la instancia recibida sincronizada al terminar.
     * Estado y auditoria comparten transaccion: si la bitacora falla, el cambio se revierte.
     *
     * @template TResult
     *
     * @param  Closure(User): TResult  $callback
     * @return TResult
     */
    private function withLockedUser(User $user, Closure $callback): mixed
    {
        $locked = null;

        $result = DB::transaction(function () use ($user, $callback, &$locked): mixed {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            return $callback($locked);
        });

        $user->setRawAttributes($locked->getAttributes(), true);

        return $result;
    }

    private function clearTwoFactorColumns(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestamp' => null,
        ])->save();
    }

    /**
     * @return int|null marca de tiempo (periodo TOTP) aceptada, o null si no es valida / ya se uso
     */
    private function verifyCode(User $user, string $code): ?int
    {
        $result = $this->google2fa->verifyKeyNewer(
            $user->two_factor_secret,
            preg_replace('/\s+/', '', $code) ?? '',
            // 0 (no null): asi google2fa devuelve el periodo TOTP aceptado y no solo `true`.
            $user->two_factor_last_timestamp ?? 0,
        );

        return $result === false ? null : (int) $result;
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        return array_map(
            fn (): string => Str::lower(Str::random(5)).'-'.Str::lower(Str::random(5)),
            range(1, (int) config('tickets.two_factor.recovery_codes')),
        );
    }

    /**
     * @param  list<string>  $plainCodes
     * @return list<string>
     */
    private function hashCodes(array $plainCodes): array
    {
        return array_map(
            fn (string $code): string => Hash::make($this->normalizeRecoveryCode($code)),
            $plainCodes,
        );
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return Str::lower(str_replace(['-', ' '], '', trim($code)));
    }

    private function renderQrSvg(string $uri): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle((int) config('tickets.two_factor.qr_size')),
            new SvgImageBackEnd,
        );

        // Se elimina la declaracion XML para incrustar el SVG en HTML.
        return Str::after((new Writer($renderer))->writeString($uri), '?>');
    }
}
