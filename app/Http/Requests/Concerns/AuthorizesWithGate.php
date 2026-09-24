<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Facades\Gate;

/**
 * La autorizacion corre ANTES de validar (asi un usuario sin acceso no aprende nada de las reglas) y
 * usa `Gate::authorize`, que respeta el 404 de las Policies para lo que queda fuera del alcance del
 * usuario (en vez de forzar siempre 403).
 */
trait AuthorizesWithGate
{
    /**
     * @param  mixed  $arguments  Modelo/clase o arreglo de argumentos de la Policy.
     */
    protected function authorizeAbility(string $ability, mixed $arguments): bool
    {
        Gate::forUser($this->user())->authorize($ability, $arguments);

        return true;
    }
}
