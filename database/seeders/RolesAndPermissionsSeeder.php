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
     * Matriz de CLAUDE.md seccion 5 (solo lo que existe hasta el Sprint 2). El permiso habilita la
     * accion; las Policies limitan el alcance (jefe: todo, coordinador: su equipo, empleado: lo suyo).
     *
     * @return array<string, list<string>>
     */
    private function permissionsByRole(): array
    {
        $operational = [
            PermissionName::TicketsView,
            PermissionName::TicketsCreate,
            PermissionName::TicketsWork,
        ];

        $managerial = [
            ...$operational,
            PermissionName::TicketsAssign,
            PermissionName::TicketsReview,
            PermissionName::TicketsManage,
        ];

        return [
            UserRole::JefeZona->value => array_column(PermissionName::cases(), 'value'),
            UserRole::Coordinador->value => $this->values($managerial),
            UserRole::Empleado->value => $this->values($operational),
        ];
    }

    /**
     * @param  list<PermissionName>  $permissions
     * @return list<string>
     */
    private function values(array $permissions): array
    {
        return array_map(fn (PermissionName $permission): string => $permission->value, $permissions);
    }
}
