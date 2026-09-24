<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\AssignmentRole;
use App\Enums\Priority;
use App\Models\Activity;
use App\Models\Category;
use App\Models\Ticket;
use Carbon\Carbon;

/**
 * Escenario con datos conocidos para las metricas del panel. "Hoy" queda congelado en 2026-09-24 15:00 UTC
 * (10:00 en Cancun), asi que el periodo por defecto (30 dias) es 2026-08-26 .. 2026-09-24 y sus limites en UTC son
 * 2026-08-26 05:00 .. 2026-09-25 05:00.
 *
 * Tickets: T1 pendiente vencido (alta, empA1) | T2 en proceso (urgente, empA1 resp + empA2 colab) | T3 en
 * revision (baja, empA2) | T4 completado en 48 h (cat. C1, empA1) | T5 completado en 12 h (equipo B, sin categoria) |
 * T6 pendiente vencido (equipo B, empB1) | T7 cancelado | T8 completado FUERA del periodo | T9 eliminado (soft delete) |
 * T10 pendiente creado antes del periodo, sin asignar.
 * Actividades: A1 en proceso vencida (alta, empA1) | A2 completada en 6 h (cat. C1) | A3 plantilla recurrente
 * (no cuenta) | A4 pendiente del equipo B.
 */
trait BuildsDashboardScenario
{
    use BuildsActivityScenario;

    protected Category $categoryC1;

    /** @var array<string, Ticket> */
    protected array $tickets = [];

    /** @var array<string, Activity> */
    protected array $activities = [];

    protected function freezeToday(): void
    {
        Carbon::setTestNow('2026-09-24 15:00:00');
        config(['tickets.dashboard.cache_ttl' => 0]);
    }

    protected function buildDashboardData(): void
    {
        $this->categoryC1 = Category::factory()->create(['name' => 'Soporte']);
        $at = fn (string $utc): array => ['created_at' => $utc, 'updated_at' => $utc];

        $this->tickets['T1'] = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->priority(Priority::High)
            ->assignedTo($this->empA1)->create([...$at('2026-09-20 12:00:00'), 'due_date' => '2026-09-20']);
        $this->tickets['T2'] = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->inProgress()->priority(Priority::Urgent)
            ->assignedTo($this->empA1)->create([...$at('2026-09-22 12:00:00'), 'due_date' => '2026-10-01']);
        $this->tickets['T2']->assignments()->create(['user_id' => $this->empA2->id, 'role' => AssignmentRole::Colaborador, 'assigned_by' => $this->jefe->id]);
        $this->tickets['T3'] = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA2)->inReview()->priority(Priority::Low)
            ->assignedTo($this->empA2)->create($at('2026-09-23 12:00:00'));
        $this->tickets['T4'] = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->completed()
            ->assignedTo($this->empA1)->create([...$at('2026-09-21 12:00:00'), 'category_id' => $this->categoryC1->id, 'completed_at' => '2026-09-23 12:00:00']);
        $this->tickets['T5'] = Ticket::factory()->forTeam($this->teamB)->createdBy($this->empB1)->completed()
            ->create([...$at('2026-09-20 00:00:00'), 'completed_at' => '2026-09-20 12:00:00']);
        $this->tickets['T6'] = Ticket::factory()->forTeam($this->teamB)->createdBy($this->empB1)
            ->assignedTo($this->empB1)->create([...$at('2026-09-24 12:00:00'), 'due_date' => '2026-09-01']);
        $this->tickets['T7'] = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->cancelled()
            ->create($at('2026-09-10 12:00:00'));
        $this->tickets['T8'] = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->completed()
            ->create([...$at('2026-07-30 12:00:00'), 'completed_at' => '2026-08-01 12:00:00']);
        $this->tickets['T9'] = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->create($at('2026-09-22 12:00:00'));
        $this->tickets['T9']->delete();
        $this->tickets['T10'] = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->create($at('2026-08-01 12:00:00'));

        $this->activities['A1'] = Activity::factory()->forTeam($this->teamA)->createdBy($this->coordA)->inProgress()->priority(Priority::High)
            ->assignedTo($this->empA1)->create([...$at('2026-09-22 12:00:00'), 'due_date' => '2026-09-20']);
        $this->activities['A2'] = Activity::factory()->forTeam($this->teamA)->createdBy($this->coordA)->completed()
            ->create([...$at('2026-09-22 00:00:00'), 'category_id' => $this->categoryC1->id, 'completed_at' => '2026-09-22 06:00:00']);
        $this->activities['A3'] = Activity::factory()->forTeam($this->teamA)->createdBy($this->coordA)->recurring()
            ->create($at('2026-09-22 12:00:00'));
        $this->activities['A4'] = Activity::factory()->forTeam($this->teamB)->createdBy($this->coordB)
            ->create($at('2026-09-23 12:00:00'));
    }
}
