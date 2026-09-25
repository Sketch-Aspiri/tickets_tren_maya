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
     * Matriz de CLAUDE.md seccion 5 (solo lo que existe hasta el Sprint 5). El permiso habilita la
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
            // Actividades: el empleado solo ve las suyas y avanza hasta En revision (sin crear ni editar).
            PermissionName::ActivitiesView,
            PermissionName::ActivitiesWork,
        ];

        $managerial = [
            ...$operational,
            PermissionName::TicketsAssign,
            PermissionName::TicketsReview,
            PermissionName::TicketsManage,
            PermissionName::ActivitiesCreate,
            PermissionName::ActivitiesAssign,
            PermissionName::ActivitiesReview,
            PermissionName::ActivitiesManage,
            // Sprint 5: panel de seguimiento (su alcance lo limita DashboardScope) y exportacion. La bitacora
            // (audit.view) la tienen jefe y administrador; `admins.manage` es exclusivo del administrador.
            PermissionName::DashboardView,
            PermissionName::ExportsCreate,
        ];

        $jefe = array_values(array_filter(
            PermissionName::cases(),
            fn (PermissionName $permission): bool => $permission !== PermissionName::AdminsManage,
        ));

        return [
            UserRole::Administrador->value => array_column(PermissionName::cases(), 'value'),
            UserRole::JefeZona->value => $this->values($jefe),
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
