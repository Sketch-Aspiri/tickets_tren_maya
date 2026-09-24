<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Permisos de Spatie. Solo los usados hasta el Sprint 1; los sprints
 * posteriores agregan los suyos en RolesAndPermissionsSeeder.
 */
enum PermissionName: string
{
    case UsersManage = 'users.manage';
    case UsersApprove = 'users.approve';
    case TeamsManage = 'teams.manage';
}
