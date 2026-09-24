<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Category;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Categorías: solo el jefe de zona (permiso `categories.manage`).
 */
class CategoryPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::CategoriesManage);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::CategoriesManage);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->hasPermission($user, PermissionName::CategoriesManage);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->hasPermission($user, PermissionName::CategoriesManage);
    }
}
