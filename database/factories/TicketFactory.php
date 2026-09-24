<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssignmentRole;
use App\Enums\Priority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\Assignment;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Los folios de fabrica usan el prefijo `TF-` para no chocar con los `TM-` que genera FolioGenerator.
 *
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    private static int $sequence = 0;

    /**
     * Ticket pendiente, sin asignar (bolsa), de un equipo nuevo y creado por un empleado de ese equipo.
     * status/team_id/created_by/folio no son fillable: las fábricas crean sin restricción de asignación masiva.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'folio' => sprintf('TF-%d-%05d', LocalTime::year(), ++self::$sequence),
            'title' => 'Ticket '.fake()->unique()->words(3, true),
            'description' => fake()->paragraph(),
            'priority' => Priority::Medium,
            'status' => TicketStatus::Pending,
            'category_id' => null,
            'team_id' => Team::factory(),
            'created_by' => fn (array $attributes): int => User::factory()
                ->empleado()
                ->create(['team_id' => $attributes['team_id']])
                ->getKey(),
            'source' => TicketSource::Web,
            'due_date' => null,
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
     * Deja el ticket asignado (por defecto como responsable).
     */
    public function assignedTo(User $user, AssignmentRole $role = AssignmentRole::Responsable): static
    {
        return $this->afterCreating(function (Ticket $ticket) use ($user, $role): void {
            $assignment = new Assignment(['user_id' => $user->getKey(), 'role' => $role]);
            $ticket->assignments()->save($assignment);
        });
    }
}
