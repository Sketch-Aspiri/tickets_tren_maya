<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\PermissionName;
use App\Models\User;

trait ChecksPermissions
{
    /**
     * Toda autorizacion exige cuenta activa con rol Y el permiso de Spatie correspondiente.
     */
    protected function hasPermission(User $user, PermissionName $permission): bool
    {
        return $user->canAccessApplication() && $user->checkPermissionTo($permission->value);
    }
}
