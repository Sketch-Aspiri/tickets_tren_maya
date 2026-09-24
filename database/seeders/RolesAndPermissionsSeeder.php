<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotente: se puede ejecutar en cada despliegue sin duplicar ni perder asignaciones de usuarios.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach ($this->permissionsByRole() as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Matriz de CLAUDE.md seccion 5 (solo lo que existe hasta el Sprint 1).
     *
     * @return array<string, list<string>>
     */
    private function permissionsByRole(): array
    {
        return [
            UserRole::JefeZona->value => array_column(PermissionName::cases(), 'value'),
            UserRole::Coordinador->value => [],
            UserRole::Empleado->value => [],
        ];
    }
}
