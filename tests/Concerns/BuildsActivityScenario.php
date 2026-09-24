<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\AssignmentRole;
use App\Models\Activity;
use App\Models\Subtask;
use App\Models\Team;
use App\Models\User;

/**
 * Escenario de actividades: el mismo de tickets (jefe, dos equipos con coordinador y empleados) más
 * atajos para crear actividades, asignaciones y subtareas sin pasar por HTTP.
 */
trait BuildsActivityScenario
{
    use BuildsTicketScenario;

    /**
     * Actividad pendiente del equipo, creada por su coordinador, sin asignados salvo que se indiquen.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeActivity(Team $team, array $attributes = [], ?User $responsible = null): Activity
    {
        $creator = $team->is($this->teamA) ? $this->coordA : $this->coordB;
        $activity = Activity::factory()->forTeam($team)->createdBy($creator)->create($attributes);

        if ($responsible !== null) {
            $this->assignTo($activity, $responsible);
        }

        return $activity;
    }

    protected function assignTo(Activity $activity, User $user, AssignmentRole $role = AssignmentRole::Responsable): void
    {
        $activity->assignments()->create(['user_id' => $user->getKey(), 'role' => $role, 'assigned_by' => $this->jefe->getKey()]);
    }

    protected function makeSubtask(Activity $activity, string $title = 'Subtarea', bool $done = false, ?User $assignee = null): Subtask
    {
        return Subtask::factory()->for($activity)->create([
            'title' => $title,
            'done' => $done,
            'done_at' => $done ? now() : null,
            'assigned_to' => $assignee?->getKey(),
        ]);
    }
}
