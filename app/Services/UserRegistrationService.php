<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\NewUserPendingApproval;
use Illuminate\Support\Facades\Notification;

/**
 * Registro propio: la cuenta nace `pending`, sin rol ni equipo, y se avisa a los administradores y jefes de zona.
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

        $this->notifyApprovers($user);

        return $user;
    }

    /**
     * Alta directa de un jefe de zona desde consola (bootstrap del sistema, sin aprobacion previa).
     */
    public function createJefe(string $name, string $email, string $password): User
    {
        return $this->createFromConsole(UserRole::JefeZona, $name, $email, $password);
    }

    /**
     * Alta directa de un administrador desde consola: el rol de administrador no se puede otorgar desde el registro
     * publico ni por un jefe de zona, asi que el primero se crea aqui.
     */
    public function createAdministrador(string $name, string $email, string $password): User
    {
        return $this->createFromConsole(UserRole::Administrador, $name, $email, $password);
    }

    private function createFromConsole(UserRole $role, string $name, string $email, string $password): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['status' => UserStatus::Active])->save();
        $user->assignRole($role->value);

        $this->audit->record('users', 'created_by_console', $user, null, extra: ['role' => $role->value]);

        return $user;
    }

    private function notifyApprovers(User $pending): void
    {
        $approvers = User::query()
            ->role([UserRole::Administrador->value, UserRole::JefeZona->value])
            ->where('status', UserStatus::Active->value)
            ->get();

        Notification::send($approvers, new NewUserPendingApproval($pending->id, $pending->name, $pending->email));
    }
}
