<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssignmentRole;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Team;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Los folios de fábrica usan el prefijo `AF-` para no chocar con los `ACT-` de FolioGenerator.
 *
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    private static int $sequence = 0;

    /**
     * Actividad pendiente, normal (sin recurrencia), de un equipo nuevo y creada por su coordinador.
     * status/team_id/created_by/folio no son fillable: las fábricas crean sin restricción de asignación masiva.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'folio' => sprintf('AF-%d-%05d', LocalTime::year(), ++self::$sequence),
            'title' => 'Actividad '.fake()->unique()->words(3, true),
            'description' => fake()->paragraph(),
            'priority' => Priority::Medium,
            'status' => TicketStatus::Pending,
            'category_id' => null,
            'team_id' => Team::factory(),
            'created_by' => fn (array $attributes): int => User::factory()
                ->coordinador()
                ->create(['team_id' => $attributes['team_id']])
                ->getKey(),
            'start_date' => null,
            'due_date' => null,
            'recurrence_rule' => null,
            'parent_activity_id' => null,
            'occurrence_date' => null,
            'completed_at' => null,
        ];
    }

    public function forTeam(Team $team): static
    {
        return $this->state(fn () => ['team_id' => $team->getKey()]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn () => ['created_by' => $user->getKey()]);
    }

    public function status(TicketStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'completed_at' => $status === TicketStatus::Completed ? now() : null,
        ]);
    }

    public function inProgress(): static
    {
        return $this->status(TicketStatus::InProgress);
    }

    public function inReview(): static
    {
        return $this->status(TicketStatus::InReview);
    }

    public function completed(): static
    {
        return $this->status(TicketStatus::Completed);
    }

    public function cancelled(): static
    {
        return $this->status(TicketStatus::Cancelled);
    }

    public function priority(Priority $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => ['due_date' => now()->subDays(3)->toDateString()]);
    }

    public function dueOn(string $date): static
    {
        return $this->state(fn () => ['due_date' => $date]);
    }

    /**
     * Plantilla semanal (lunes) que arranca hoy; se puede reemplazar la regla.
     *
     * @param  array<string, mixed>|null  $rule
     */
    public function recurring(?array $rule = null): static
    {
        return $this->state(fn () => [
            'recurrence_rule' => $rule ?? ['frequency' => 'weekly', 'interval' => 1, 'days_of_week' => [1], 'day_of_month' => null, 'ends_at' => null],
            'start_date' => LocalTime::today(),
        ]);
    }

    /**
     * Instancia de una plantilla para la fecha de ocurrencia dada.
     */
    public function instanceOf(Activity $template, string $occurrenceDate): static
    {
        return $this->state(fn () => [
            'team_id' => $template->team_id,
            'created_by' => $template->created_by,
            'parent_activity_id' => $template->getKey(),
            'occurrence_date' => $occurrenceDate,
            'start_date' => $occurrenceDate,
        ]);
    }

    /**
     * Deja la actividad asignada (por defecto como responsable).
     */
    public function assignedTo(User $user, AssignmentRole $role = AssignmentRole::Responsable): static
    {
        return $this->afterCreating(function (Activity $activity) use ($user, $role): void {
            $activity->assignments()->save(new Assignment(['user_id' => $user->getKey(), 'role' => $role]));
        });
    }
}
