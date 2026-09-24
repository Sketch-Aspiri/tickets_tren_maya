<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Un adjunto hereda el permiso de su padre (ticket o actividad, resuelto por la relación polimórfica):
 * quien puede VER el padre puede descargar sus adjuntos. Fuera de alcance responde 404. Si el padre ya no
 * existe (eliminado) o es de un tipo desconocido, nadie descarga.
 */
class AttachmentPolicy
{
    use ChecksPermissions;

    public function view(User $user, Attachment $attachment): Response|bool
    {
        return $this->parentAllows($user, $attachment) ? true : Response::denyAsNotFound();
    }

    /**
     * Borrar: su autor, o quien gestiona el padre (jefe / coordinador del alcance).
     */
    public function delete(User $user, Attachment $attachment): Response|bool
    {
        if (! $this->parentAllows($user, $attachment)) {
            return Response::denyAsNotFound();
        }

        return (int) $attachment->user_id === (int) $user->getKey()
            || $this->hasPermission($user, $this->managePermission($attachment));
    }

    private function managePermission(Attachment $attachment): PermissionName
    {
        return $attachment->attachable instanceof Activity ? PermissionName::ActivitiesManage : PermissionName::TicketsManage;
    }

    private function parentAllows(User $user, Attachment $attachment): bool
    {
        $parent = $attachment->attachable;

        if (! $parent instanceof Ticket && ! $parent instanceof Activity) {
            return false;
        }

        return Gate::forUser($user)->inspect('view', $parent)->allowed();
    }
}
