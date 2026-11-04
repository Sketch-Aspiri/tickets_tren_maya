<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\CoordinationMailbox;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Buzones de coordinacion: solo quien tiene `org.manage` (administrador).
 */
class CoordinationMailboxPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::OrgManage);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::OrgManage);
    }

    public function update(User $user, CoordinationMailbox $mailbox): bool
    {
        return $this->hasPermission($user, PermissionName::OrgManage);
    }

    public function delete(User $user, CoordinationMailbox $mailbox): bool
    {
        return $this->hasPermission($user, PermissionName::OrgManage);
    }

    public function test(User $user, CoordinationMailbox $mailbox): bool
    {
        return $this->hasPermission($user, PermissionName::OrgManage);
    }
}
