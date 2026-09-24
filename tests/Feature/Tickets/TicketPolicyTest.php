<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\BuildsTicketScenario;
use Tests\DatabaseTestCase;

/**
 * Matriz de permisos de la seccion 5 de CLAUDE.md sobre TicketPolicy (jefe / coordinador / empleado,
 * mas cuentas pendientes e inactivas), independiente de las rutas.
 */
class TicketPolicyTest extends DatabaseTestCase
{
    use BuildsTicketScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    private function can(User $user, string $ability, mixed ...$arguments): bool
    {
        return Gate::forUser($user)->allows($ability, $arguments);
    }

    public function test_create_and_view_any_for_every_active_role_but_not_for_pending_inactive_or_roleless(): void
    {
        foreach ([$this->jefe, $this->coordA, $this->empA1] as $user) {
            $this->assertTrue($this->can($user, 'create', Ticket::class));
            $this->assertTrue($this->can($user, 'viewAny', Ticket::class));
        }

        $denied = [
            User::factory()->pending()->create(),
            User::factory()->empleado()->inactive()->create(),
            User::factory()->active()->create(),
        ];

        foreach ($denied as $user) {
            $this->assertFalse($this->can($user, 'create', Ticket::class));
            $this->assertFalse($this->can($user, 'viewAny', Ticket::class));
        }
    }

    public function test_view_follows_the_role_scope(): void
    {
        $ticketA = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->create();

        $this->assertTrue($this->can($this->jefe, 'view', $ticketA));
        $this->assertTrue($this->can($this->coordA, 'view', $ticketA));
        $this->assertTrue($this->can($this->empA1, 'view', $ticketA));
        $this->assertTrue($this->can($this->empA2, 'view', $ticketA), 'bolsa de su equipo');
        $this->assertFalse($this->can($this->coordB, 'view', $ticketA));
        $this->assertFalse($this->can($this->empB1, 'view', $ticketA));
    }

    public function test_out_of_scope_denials_are_404_and_in_scope_denials_are_403(): void
    {
        $ticketA = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->create();

        $outOfScope = Gate::forUser($this->coordB)->inspect('assign', $ticketA);
        $inScope = Gate::forUser($this->empA2)->inspect('assign', $ticketA);

        $this->assertTrue($outOfScope->denied());
        $this->assertSame(404, $outOfScope->status());
        $this->assertTrue($inScope->denied());
        $this->assertNull($inScope->status());
    }

    public function test_permission_matrix_per_role_for_a_ticket_in_progress_of_team_a(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->inProgress()->assignedTo($this->empA1)->create();

        // ability => [jefe, coordinador A, empleado A1 (creador y asignado), empleado A2 (mismo equipo, ajeno), coordinador B]
        $matrix = [
            'view' => [true, true, true, false, false],
            'update' => [true, true, false, false, false], // el creador solo edita en Pendiente
            'delete' => [true, true, false, false, false],
            'assign' => [true, true, false, false, false],
            'take' => [false, true, true, false, false], // el jefe no tiene equipo; el ticket ya esta asignado (lo valida el servicio)
            'comment' => [true, true, true, false, false],
            'attach' => [true, true, true, false, false],
        ];
        $actors = [$this->jefe, $this->coordA, $this->empA1, $this->empA2, $this->coordB];

        foreach ($matrix as $ability => $expected) {
            foreach ($actors as $index => $actor) {
                $this->assertSame($expected[$index], $this->can($actor, $ability, $ticket), "{$ability} para {$actor->name}");
            }
        }
    }

    public function test_transition_matrix_by_target_state(): void
    {
        $inProgress = Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)->inProgress()->assignedTo($this->empA1)->create();
        $inReview = Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)->inReview()->assignedTo($this->empA1)->create();
        $done = Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)->completed()->assignedTo($this->empA1)->create();

        // Avanzar hasta En revision: empleado solo lo suyo; jefe y coordinador cualquiera de su alcance.
        $this->assertTrue($this->can($this->empA1, 'transition', $inProgress, TicketStatus::InReview));
        $this->assertFalse($this->can($this->empA2, 'transition', $inProgress, TicketStatus::InReview));
        $this->assertTrue($this->can($this->coordA, 'transition', $inProgress, TicketStatus::InReview));
        $this->assertTrue($this->can($this->jefe, 'transition', $inProgress, TicketStatus::InReview));
        $this->assertFalse($this->can($this->coordB, 'transition', $inProgress, TicketStatus::InReview));

        // Aprobar / rechazar: solo jefe y coordinador del equipo.
        foreach ([TicketStatus::Completed, TicketStatus::InProgress] as $target) {
            $this->assertFalse($this->can($this->empA1, 'transition', $inReview, $target));
            $this->assertTrue($this->can($this->coordA, 'transition', $inReview, $target));
            $this->assertTrue($this->can($this->jefe, 'transition', $inReview, $target));
            $this->assertFalse($this->can($this->coordB, 'transition', $inReview, $target));
        }

        // Cancelar y reabrir: solo jefe y coordinador del equipo.
        $this->assertFalse($this->can($this->empA1, 'transition', $inProgress, TicketStatus::Cancelled));
        $this->assertTrue($this->can($this->coordA, 'transition', $inProgress, TicketStatus::Cancelled));
        $this->assertFalse($this->can($this->empA1, 'transition', $done, TicketStatus::Pending));
        $this->assertTrue($this->can($this->coordA, 'transition', $done, TicketStatus::Pending));
        $this->assertTrue($this->can($this->jefe, 'transition', $done, TicketStatus::Pending));
        $this->assertFalse($this->can($this->coordB, 'transition', $done, TicketStatus::Pending));
    }

    public function test_creator_can_update_only_while_pending_and_final_tickets_are_never_editable(): void
    {
        $pending = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->create();
        $done = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->completed()->create();

        $this->assertTrue($this->can($this->empA1, 'update', $pending));
        $this->assertFalse($this->can($this->empA2, 'update', $pending));
        $this->assertFalse($this->can($this->empA1, 'update', $done));
        $this->assertFalse($this->can($this->jefe, 'update', $done));
    }

    public function test_attachment_permissions_inherit_from_the_ticket(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->create();
        $attachment = Attachment::factory()->for($ticket, 'attachable')->create(['user_id' => $this->empA1->id]);

        $this->assertTrue($this->can($this->empA1, 'view', $attachment));
        $this->assertTrue($this->can($this->empA2, 'view', $attachment));
        $this->assertTrue($this->can($this->coordA, 'view', $attachment));
        $this->assertFalse($this->can($this->coordB, 'view', $attachment));
        $this->assertFalse($this->can($this->empB1, 'view', $attachment));

        $this->assertTrue($this->can($this->empA1, 'delete', $attachment), 'autor');
        $this->assertFalse($this->can($this->empA2, 'delete', $attachment));
        $this->assertTrue($this->can($this->coordA, 'delete', $attachment));
        $this->assertTrue($this->can($this->jefe, 'delete', $attachment));
        $this->assertFalse($this->can($this->coordB, 'delete', $attachment));
    }

    public function test_orphaned_attachment_is_denied_to_everyone(): void
    {
        $attachment = Attachment::factory()->create(['attachable_type' => 'ticket', 'attachable_id' => 999999]);

        $this->assertFalse($this->can($this->jefe, 'view', $attachment));
        $this->assertFalse($this->can($this->jefe, 'delete', $attachment));
    }

    public function test_pending_and_inactive_accounts_are_denied_every_ticket_ability(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->create();
        $inactive = User::factory()->empleado()->inactive()->create(['team_id' => $this->teamA->id]);
        $pending = User::factory()->pending()->create();

        foreach ([$inactive, $pending] as $user) {
            foreach (['view', 'update', 'delete', 'assign', 'take', 'comment', 'attach'] as $ability) {
                $this->assertFalse($this->can($user, $ability, $ticket), "{$ability} para {$user->status->value}");
            }

            $this->assertFalse($this->can($user, 'transition', $ticket, TicketStatus::InProgress));
        }
    }
}
