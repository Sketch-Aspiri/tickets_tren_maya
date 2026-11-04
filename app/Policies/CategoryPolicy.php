<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Category;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Categorias (permiso `categories.manage`): el administrador gestiona todas (incluidas las globales); el jefe de
 * zona solo las de sus coordinaciones. Una categoria fuera de su alcance responde 404.
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

    public function update(User $user, Category $category): Response|bool
    {
        return $this->scoped($user, $category);
    }

    public function delete(User $user, Category $category): Response|bool
    {
        return $this->scoped($user, $category);
    }

    private function scoped(User $user, Category $category): Response|bool
    {
        if (! $this->hasPermission($user, PermissionName::CategoriesManage)) {
            return false;
        }

        $inScope = $user->seesAllTeams()
            || ($category->coordination_id !== null && in_array((int) $category->coordination_id, $user->coordinationIds(), true));

        return $inScope ? true : Response::denyAsNotFound();
    }
}
