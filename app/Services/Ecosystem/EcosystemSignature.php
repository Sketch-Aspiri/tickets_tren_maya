<?php

declare(strict_types=1);

namespace App\Services\Ecosystem;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Codigos firmados que viajan entre Tickets y el Dashboard por el navegador (inicio y cierre de
 * sesion unicos, decision 88). Es la misma clase que App\Services\Ecosistema\FirmaEcosistema del
 * Dashboard, con el mismo formato (no cambiar uno sin el otro):
 *
 *   base64url(json{v, iss, aud, act, email, mfa, state, nonce, exp}) . "." . base64url(hmac_sha256)
 *
 * `state` ata el codigo de entrada al navegador que lo pidio (un codigo ajeno enviado como enlace no sirve).
 * `lnk` dice si la cuenta de ORIGEN esta vinculada (el correo del otro sistema se puede editar sin verificar):
 * el destino solo abre sesion sin contrasena si ambas cuentas lo estan. Sin `lnk`, vale false.
 *
 * El secreto (ECOSISTEMA_SSO_SECRET) es el mismo en ambos .env y nunca sale
 * del servidor. Un código vale para un solo destino, una sola acción, 60 s y
 * una sola vez (el nonce se consume en caché).
 */
final class EcosystemSignature
{
    public const ENTER = 'entrar';

    public const LEAVE = 'salir';

    public function configured(): bool
    {
        return strlen((string) config('ecosistema.sso.secreto')) >= 32;
    }

    public function sign(string $destino, string $accion, string $email, bool $mfa, ?string $estado = null, bool $linked = false): string
    {
        $carga = $this->b64(json_encode([
            'v' => 1,
            'iss' => config('ecosistema.sistema'),
            'aud' => $destino,
            'act' => $accion,
            'email' => mb_strtolower($email),
            'mfa' => $mfa,
            'state' => $estado,
            'lnk' => $linked,
            'nonce' => Str::random(32),
            'exp' => now()->addSeconds((int) config('ecosistema.sso.vigencia', 60))->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        return $carga.'.'.$this->b64(hash_hmac('sha256', $carga, $this->secreto(), true));
    }

    /**
     * Devuelve la carga si el código es auténtico, va dirigido a este sistema,
     * es de la acción esperada, no venció y no se usó antes; si no, null.
     *
     * @return array{iss: string, email: string, mfa: bool, state: ?string, lnk: bool}|null
     */
    public function verify(?string $codigo, string $accion): ?array
    {
        if (! $this->configured() || ! is_string($codigo) || strlen($codigo) > 2048 || substr_count($codigo, '.') !== 1) {
            return null;
        }

        [$carga, $firma] = explode('.', $codigo);
        $esperada = $this->b64(hash_hmac('sha256', $carga, $this->secreto(), true));

        if (! hash_equals($esperada, $firma)) {
            return null;
        }

        $datos = json_decode((string) base64_decode(strtr($carga, '-_', '+/'), true), true);

        if (! is_array($datos)
            || ($datos['v'] ?? null) !== 1
            || ($datos['aud'] ?? null) !== config('ecosistema.sistema')
            || ($datos['act'] ?? null) !== $accion
            || ! array_key_exists((string) ($datos['iss'] ?? ''), (array) config('ecosistema.urls'))
            || ($datos['iss'] ?? null) === config('ecosistema.sistema')
            || ! is_string($datos['email'] ?? null)
            || ! is_string($datos['nonce'] ?? null)
            || ! is_int($datos['exp'] ?? null)
            || $datos['exp'] < now()->getTimestamp()) {
            return null;
        }

        // Un solo uso: el primero que lo canjea gana.
        if (! Cache::add('ecosistema:nonce:'.hash('sha256', $datos['nonce']), true, 300)) {
            return null;
        }

        return [
            'iss' => $datos['iss'],
            'email' => $datos['email'],
            'mfa' => (bool) ($datos['mfa'] ?? false),
            'state' => is_string($datos['state'] ?? null) ? $datos['state'] : null,
            'lnk' => ($datos['lnk'] ?? false) === true,
        ];
    }

    private function secreto(): string
    {
        return (string) config('ecosistema.sso.secreto');
    }

    private function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
