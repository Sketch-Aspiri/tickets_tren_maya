<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\NewUserPendingApproval;
use Illuminate\Support\Facades\Notification;

/**
 * Registro propio: la cuenta nace `pending`, sin rol ni equipo, y se avisa a los jefes de zona.
 */
final class UserRegistrationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function register(array $data): User
    {
        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        $user->forceFill(['status' => UserStatus::Pending])->save();

        $this->notifyJefes($user);

        return $user;
    }

    /**
     * Alta directa de un jefe de zona desde consola (bootstrap del sistema, sin aprobacion previa).
     */
    public function createJefe(string $name, string $email, string $password): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['status' => UserStatus::Active])->save();
        $user->assignRole(UserRole::JefeZona->value);

        $this->audit->record('users', 'created_by_console', $user, null, extra: ['role' => UserRole::JefeZona->value]);

        return $user;
    }

    private function notifyJefes(User $pending): void
    {
        $jefes = User::query()->activeWithRole(UserRole::JefeZona)->get();

        Notification::send($jefes, new NewUserPendingApproval($pending->id, $pending->name, $pending->email));
    }
}
