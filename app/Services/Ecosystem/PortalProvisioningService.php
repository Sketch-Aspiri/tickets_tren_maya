<?php

declare(strict_types=1);

namespace App\Services\Ecosystem;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Crea la cuenta que el portal aprobo (decision 99): activa, con rol, equipos (o coordinaciones si es jefe de zona),
 * vinculada al portal y con la MISMA contrasena (llega su hash bcrypt por el puente firmado).
 *
 * La autoridad es la aprobacion del portal y la firma del puente, no un usuario de aqui. Por eso NO pasa por
 * UserService::approve (que exige un actor y su alcance). Reglas que se conservan: coordinador y empleado
 * necesitan equipo, el jefe de zona coordinaciones.
 *
 * Nunca toca una cuenta existente con ese correo: el correo no esta verificado, asi que emparejarla solo por el
 * correo permitiria a quien lo escriba en el portal entrar a la cuenta de otra persona. Esas cuentas se vinculan
 * con su contrasena la primera vez que entran (el vinculo de la decision 88).
 */
final class PortalProvisioningService
{
    public const CREATED = 'creada';

    public const ALREADY = 'ya_aprovisionada';

    public const EMAIL_TAKEN = 'correo_existente';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{portal_user_id: string, name: string, email: string, password_hash: string, role: string, team_ids?: list<int>|null, coordination_ids?: list<int>|null}  $data
     * @return array{estado: string, user_id?: int}
     */
    public function provision(array $data): array
    {
        $email = mb_strtolower($data['email']);
        $role = UserRole::from($data['role']);

        return DB::transaction(function () use ($data, $email, $role): array {
            $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();

            if ($existing !== null) {
                // Reintento del mismo alta: idempotente. Cualquier otra cuenta con ese correo se respeta.
                return $existing->portal_user_id === $data['portal_user_id']
                    ? ['estado' => self::ALREADY, 'user_id' => $existing->id]
                    : ['estado' => self::EMAIL_TAKEN];
            }

            $user = new User(['name' => trim($data['name']), 'email' => $email, 'password' => bin2hex(random_bytes(16))]);
            $user->forceFill([
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'portal_user_id' => $data['portal_user_id'],
                'sso_linked_at' => now(),
            ])->save();

            // El cast `hashed` volveria a cifrar un hash con otro costo: se escribe tal cual llego.
            DB::table('users')->where('id', $user->id)->update(['password' => $data['password_hash']]);

            $user->syncRoles([$role->value]);
            $user->teams()->sync($role->requiresTeam() ? array_map('intval', $data['team_ids'] ?? []) : []);

            if ($role === UserRole::JefeZona) {
                $user->coordinations()->sync(array_map('intval', $data['coordination_ids'] ?? []));
            }

            $this->audit->record('users', 'provisioned_by_portal', $user, null, extra: [
                'role' => $role->value,
                'teams' => $data['team_ids'] ?? [],
                'coordinations' => $data['coordination_ids'] ?? [],
            ]);

            return ['estado' => self::CREATED, 'user_id' => $user->id];
        });
    }
}
