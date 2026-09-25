<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Permisos de Spatie. Solo los usados hasta el Sprint 5; los sprints
 * posteriores agregan los suyos. La matriz por rol vive en RolesAndPermissionsSeeder.
 * El ALCANCE (equipo/propios) lo aplican las Policies, no el permiso.
 */
enum PermissionName: string
{
    case UsersManage = 'users.manage';
    /** Gestionar cuentas de administrador y otorgar ese rol (solo administrador; el jefe no puede tocarlas). */
    case AdminsManage = 'admins.manage';
    case UsersApprove = 'users.approve';
    case TeamsManage = 'teams.manage';
    case CategoriesManage = 'categories.manage';
    case TicketsView = 'tickets.view';
    case TicketsCreate = 'tickets.create';
    /** Avanzar hasta En revision, tomar de la bolsa, comentar y adjuntar. */
    case TicketsWork = 'tickets.work';
    /** Asignar, reasignar, delegar y devolver a la bolsa. */
    case TicketsAssign = 'tickets.assign';
    /** Aprobar o rechazar (En revision -> Completado / En proceso). */
    case TicketsReview = 'tickets.review';
    /** Editar cualquier ticket del alcance, eliminar, cancelar y reabrir. */
    case TicketsManage = 'tickets.manage';
    case ActivitiesView = 'activities.view';
    /** Crear actividades (jefe: cualquier equipo; coordinador: el suyo). */
    case ActivitiesCreate = 'activities.create';
    /** Avanzar hasta En revision lo propio, marcar subtareas, comentar y adjuntar. */
    case ActivitiesWork = 'activities.work';
    /** Asignar y reasignar (responsable unico + colaboradores). */
    case ActivitiesAssign = 'activities.assign';
    /** Aprobar o rechazar (En revision -> Completado / En proceso). */
    case ActivitiesReview = 'activities.review';
    /** Editar, eliminar, cancelar, reabrir y gestionar subtareas de cualquier actividad del alcance. */
    case ActivitiesManage = 'activities.manage';
    /** Panel de seguimiento: global (jefe) o de su equipo (coordinador). */
    case DashboardView = 'dashboard.view';
    /** Visor de la bitacora de auditoria (solo jefe). Solo lectura. */
    case AuditView = 'audit.view';
    /** Exportar listados filtrados a Excel (jefe y coordinador, dentro de su alcance). */
    case ExportsCreate = 'exports.create';
}
