<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Panel de seguimiento: jefe (global) y coordinador (solo su equipo). El empleado no tiene el permiso
 * `dashboard.view`. El ALCANCE de los datos NO se decide aquí sino en DashboardScope (única definición).
 * Se registra como habilidad `view-dashboard` en AppServiceProvider (no hay un modelo asociado).
 */
class DashboardPolicy
{
    use ChecksPermissions;

    public function view(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::DashboardView);
    }
}
