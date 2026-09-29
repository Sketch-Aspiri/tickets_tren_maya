<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Conversation;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Chat interno. El permiso `chat.use` habilita la funcion y `Conversation::scopeVisibleTo` limita el alcance
 * (unica definicion): fuera de alcance responde 404 para no revelar que la conversacion existe.
 */
class ConversationPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::ChatUse);
    }

    public function start(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Conversation $conversation): Response|bool
    {
        if (! $this->hasPermission($user, PermissionName::ChatUse)) {
            return false;
        }

        return Conversation::query()->visibleTo($user)->whereKey($conversation->getKey())->exists()
            ? true
            : Response::denyAsNotFound();
    }

    public function send(User $user, Conversation $conversation): Response|bool
    {
        return $this->view($user, $conversation);
    }
}
