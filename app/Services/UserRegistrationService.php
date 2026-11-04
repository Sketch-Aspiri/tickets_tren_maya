<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\NewUserPendingApproval;
use Illuminate\Support\Facades\Notification;

/**
 * Registro propio: la cuenta nace `pending`, sin rol ni equipo, con la coordinacion que la persona eligio; se avisa a
 * los jefes de zona de esa coordinacion (o a los administradores si no tiene).
 */
final class UserRegistrationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, email: string, password: string, coordination_id?: ?int}  $data
     */
    public function register(array $data): User
    {
        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        $user->forceFill([
            'status' => UserStatus::Pending,
            'requested_coordination_id' => $data['coordination_id'] ?? null,
        ])->save();

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

    /**
     * El aviso va a los jefes de zona de la coordinacion solicitada; si no tiene ninguno activo (o no se indico
     * coordinacion), a los administradores.
     */
    private function notifyApprovers(User $pending): void
    {
        $approvers = $pending->requestedCoordination?->approvers()
            ?? User::query()->activeWithRole(UserRole::Administrador)->get();

        Notification::send($approvers, new NewUserPendingApproval($pending->id, $pending->name, $pending->email));
    }
}
