<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * Falla rapido si staging/produccion arrancan con una configuracion insegura.
 * `local` y `testing` nunca se bloquean.
 */
final class EnvironmentSecurityCheck
{
    /** Entornos donde la configuracion insegura impide arrancar. */
    public const STRICT_ENVIRONMENTS = ['production', 'staging'];

    public function __construct(private readonly Application $app) {}

    public function isStrictEnvironment(): bool
    {
        return $this->app->environment(self::STRICT_ENVIRONMENTS);
    }

    /**
     * @return list<string>
     */
    public function violations(): array
    {
        if (! $this->isStrictEnvironment()) {
            return [];
        }

        $violations = [];

        if ((bool) config('app.debug')) {
            $violations[] = 'APP_DEBUG debe ser false.';
        }

        if (config('session.secure') !== true) {
            $violations[] = 'SESSION_SECURE_COOKIE debe ser true (la cookie de sesion solo viaja por HTTPS).';
        }

        if (! str_starts_with(strtolower((string) config('app.url')), 'https://')) {
            $violations[] = 'APP_URL debe usar https://.';
        }

        return $violations;
    }

    /**
     * @throws RuntimeException
     */
    public function assertSecure(): void
    {
        $violations = $this->violations();

        if ($violations !== []) {
            throw new RuntimeException(
                'Configuracion insegura para el entorno ['.$this->app->environment().']: '.implode(' ', $violations)
            );
        }
    }
}
