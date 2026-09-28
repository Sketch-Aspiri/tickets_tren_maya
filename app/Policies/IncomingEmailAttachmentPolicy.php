<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IncomingEmailAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Un adjunto de correo entrante hereda el permiso de su correo padre: quien puede VER el correo
 * (`IncomingEmailPolicy::view`) puede descargar sus adjuntos. Mismo patrón de delegación que
 * `AttachmentPolicy::parentAllows` para tickets/actividades.
 */
class IncomingEmailAttachmentPolicy
{
    public function view(User $user, IncomingEmailAttachment $incomingEmailAttachment): bool
    {
        return Gate::forUser($user)->inspect('view', $incomingEmailAttachment->incomingEmail)->allowed();
    }
}
