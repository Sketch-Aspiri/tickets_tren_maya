<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;

/**
 * Decide a que coordinacion pertenece una entrada de la bitacora: la del sujeto (ticket o actividad -> equipo ->
 * coordinacion; equipo; categoria; correo; coordinacion) o, si no la tiene, la de la persona que la causo. Lo
 * global (estaciones, categorias globales, eventos del sistema) queda sin coordinacion: solo lo ve el
 * administrador. Para un usuario se toma la coordinacion que solicito o, si no, la de su primer equipo o la
 * primera que supervisa.
 */
final class AuditCoordinationResolver
{
    private const USER_TYPE = 'App\Models\User';

    public function assign(ActivityLogEntry $entry): void
    {
        if ($entry->getAttribute('coordination_id') !== null) {
            return;
        }

        $entry->setAttribute('coordination_id', $this->resolve($entry));
    }

    public function resolve(ActivityLogEntry $entry): ?int
    {
        $fromSubject = $this->fromSubject((string) $entry->subject_type, $entry->subject_id);

        if ($fromSubject !== null) {
            return $fromSubject;
        }

        if ($entry->causer_type === self::USER_TYPE && $entry->causer_id !== null) {
            return $this->forUser((int) $entry->causer_id);
        }

        return null;
    }

    private function fromSubject(string $type, mixed $id): ?int
    {
        if ($id === null || $type === '') {
            return null;
        }

        $id = (int) $id;

        $value = match ($type) {
            'ticket' => DB::table('tickets')->join('teams', 'teams.id', '=', 'tickets.team_id')->where('tickets.id', $id)->value('teams.coordination_id'),
            'activity' => DB::table('activities')->join('teams', 'teams.id', '=', 'activities.team_id')->where('activities.id', $id)->value('teams.coordination_id'),
            'App\Models\Team' => DB::table('teams')->where('id', $id)->value('coordination_id'),
            'category' => DB::table('categories')->where('id', $id)->value('coordination_id'),
            'incoming_email' => DB::table('incoming_emails')->where('id', $id)->value('coordination_id'),
            'coordination' => DB::table('coordinations')->where('id', $id)->value('id'),
            self::USER_TYPE => $this->forUser($id),
            default => null,
        };

        return $value === null ? null : (int) $value;
    }

    private function forUser(int $userId): ?int
    {
        $fromTeam = DB::table('team_user')
            ->join('teams', 'teams.id', '=', 'team_user.team_id')
            ->where('team_user.user_id', $userId)
            ->orderBy('teams.coordination_id')
            ->value('teams.coordination_id');

        if ($fromTeam !== null) {
            return (int) $fromTeam;
        }

        $supervised = DB::table('coordination_user')->where('user_id', $userId)->orderBy('coordination_id')->value('coordination_id');

        if ($supervised !== null) {
            return (int) $supervised;
        }

        // Sin equipo ni supervision: solo cuenta lo solicitado al registrarse (cuenta aun sin ubicar).
        $requested = DB::table('users')->where('id', $userId)->where('status', '!=', 'active')->value('requested_coordination_id');

        return $requested === null ? null : (int) $requested;
    }
}
