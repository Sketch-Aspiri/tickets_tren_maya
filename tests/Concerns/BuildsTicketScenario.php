<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;

/**
 * Escenario base de los tests de tickets: dos equipos (A y B), un jefe, un coordinador por equipo
 * y empleados. Sirve para probar alcance por rol e IDOR entre equipos.
 */
trait BuildsTicketScenario
{
    protected Team $teamA;

    protected Team $teamB;

    protected User $jefe;

    protected User $coordA;

    protected User $coordB;

    protected User $empA1;

    protected User $empA2;

    protected User $empB1;

    protected function buildScenario(): void
    {
        $this->teamA = Team::factory()->create(['name' => 'Equipo A']);
        $this->teamB = Team::factory()->create(['name' => 'Equipo B']);

        $this->jefe = User::factory()->jefe()->create(['name' => 'Jefe Zona']);
        $this->coordA = User::factory()->coordinador()->create(['name' => 'Coord A', 'team_id' => $this->teamA->id]);
        $this->coordB = User::factory()->coordinador()->create(['name' => 'Coord B', 'team_id' => $this->teamB->id]);
        $this->teamA->update(['coordinator_id' => $this->coordA->id]);
        $this->teamB->update(['coordinator_id' => $this->coordB->id]);

        $this->empA1 = User::factory()->empleado()->create(['name' => 'Emp A1', 'team_id' => $this->teamA->id]);
        $this->empA2 = User::factory()->empleado()->create(['name' => 'Emp A2', 'team_id' => $this->teamA->id]);
        $this->empB1 = User::factory()->empleado()->create(['name' => 'Emp B1', 'team_id' => $this->teamB->id]);
    }

    /**
     * Ticket pendiente y sin asignar en el equipo indicado, creado por un empleado de ese equipo.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeTicket(Team $team, ?User $creator = null, array $attributes = []): Ticket
    {
        $creator ??= $team->is($this->teamA) ? $this->empA1 : $this->empB1;

        return Ticket::factory()->forTeam($team)->createdBy($creator)->create($attributes);
    }
}
