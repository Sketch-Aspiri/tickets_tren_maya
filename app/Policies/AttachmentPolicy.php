<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Un adjunto hereda el permiso de su padre (hoy solo tickets; en el Sprint 3 también actividades):
 * quien puede ver el ticket puede descargar sus adjuntos. Fuera de alcance responde 404.
 */
class AttachmentPolicy
{
    use ChecksPermissions;

    public function view(User $user, Attachment $attachment): Response|bool
    {
        return $this->parentAllows($user, $attachment) ? true : Response::denyAsNotFound();
    }

    /**
     * Borrar: su autor, o quien gestiona el ticket (jefe / coordinador del equipo).
     */
    public function delete(User $user, Attachment $attachment): Response|bool
    {
        if (! $this->parentAllows($user, $attachment)) {
            return Response::denyAsNotFound();
        }

        return (int) $attachment->user_id === (int) $user->getKey()
            || $this->hasPermission($user, PermissionName::TicketsManage);
    }

    private function parentAllows(User $user, Attachment $attachment): bool
    {
        $parent = $attachment->attachable;

        if (! $parent instanceof Ticket) {
            return false;
        }

        return Gate::forUser($user)->inspect('view', $parent)->allowed();
    }
}
