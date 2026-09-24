<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Visor de la bitácora de auditoría: solo el jefe de zona (permiso `audit.view`). Solo lectura: no existe
 * ninguna habilidad de escritura sobre la bitácora. Registrada para `Spatie\Activitylog\Models\Activity`.
 */
class AuditLogPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::AuditView);
    }
}
