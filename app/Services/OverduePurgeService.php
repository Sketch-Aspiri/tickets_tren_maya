<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Elimina (eliminación lógica, softDeletes) los tickets y actividades VENCIDOS: fecha límite anterior a hoy y
 * estado no final (la misma definición de "vencido" de los listados y el panel). Alcance: solo los equipos que el
 * usuario gestiona (`scopedTeamIdsQuery`: todos para el administrador, las coordinaciones del jefe, los equipos del
 * coordinador), dentro de lo que `visibleTo` le deja ver. Las plantillas de recurrencia no cuentan (no son trabajo
 * vencido) y lo completado o cancelado tampoco. Quién puede ejecutarlo lo decide la habilidad `purge-overdue`.
 */
final class OverduePurgeService
{
    private const CHUNK_SIZE = 200;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{tickets: int, activities: int}
     */
    public function counts(User $user): array
    {
        return [
            'tickets' => $this->tickets($user)->count(),
            'activities' => $this->activities($user)->count(),
        ];
    }

    /**
     * @return array{tickets: int, activities: int}
     */
    public function purge(User $actor): array
    {
        return DB::transaction(function () use ($actor): array {
            $result = [
                'tickets' => $this->deleteAll($this->tickets($actor), 'tickets.id'),
                'activities' => $this->deleteAll($this->activities($actor), 'activities.id'),
            ];

            $this->audit->record('maintenance', 'overdue_purged', null, $actor, extra: $result);

            return $result;
        });
    }

    /**
     * @return Builder<Ticket>
     */
    private function tickets(User $user): Builder
    {
        return Ticket::query()
            ->visibleTo($user)
            ->overdue()
            ->when($user->scopedTeamIdsQuery(), fn (Builder $query, $teams) => $query->whereIn('tickets.team_id', $teams));
    }

    /**
     * @return Builder<Activity>
     */
    private function activities(User $user): Builder
    {
        return Activity::query()
            ->visibleTo($user)
            ->withoutTemplates()
            ->overdue()
            ->when($user->scopedTeamIdsQuery(), fn (Builder $query, $teams) => $query->whereIn('activities.team_id', $teams));
    }

    /**
     * @param  Builder<Ticket>|Builder<Activity>  $query
     */
    private function deleteAll(Builder $query, string $column): int
    {
        $deleted = 0;

        // Uno por uno para que cada eliminación quede en la bitácora (LogsActivity) y respete los eventos del modelo.
        $query->chunkById(self::CHUNK_SIZE, function ($chunk) use (&$deleted): void {
            foreach ($chunk as $record) {
                $record->delete();
                $deleted++;
            }
        }, $column, 'id');

        return $deleted;
    }
}
