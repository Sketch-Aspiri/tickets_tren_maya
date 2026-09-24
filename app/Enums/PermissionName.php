<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Permisos de Spatie. Solo los usados hasta el Sprint 2; los sprints
 * posteriores agregan los suyos. La matriz por rol vive en RolesAndPermissionsSeeder.
 * El ALCANCE (equipo/propios) lo aplican las Policies, no el permiso.
 */
enum PermissionName: string
{
    case UsersManage = 'users.manage';
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
}
