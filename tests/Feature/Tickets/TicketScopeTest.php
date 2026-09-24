<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\AssignmentRole;
use App\Enums\TicketStatus;
use App\Models\Assignment;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Support\MorphMap;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsTicketScenario;
use Tests\DatabaseTestCase;

class TicketScopeTest extends DatabaseTestCase
{
    use BuildsTicketScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return list<int>
     */
    private function visibleIds(User $user): array
    {
        return Ticket::query()->visibleTo($user)->orderBy('id')->pluck('id')->all();
    }

    public function test_jefe_sees_every_ticket(): void
    {
        $a = $this->makeTicket($this->teamA);
        $b = $this->makeTicket($this->teamB);

        $this->assertSame([$a->id, $b->id], $this->visibleIds($this->jefe));
    }

    public function test_coordinator_only_sees_tickets_of_the_team_in_users_team_id(): void
    {
        $a = $this->makeTicket($this->teamA);
        $this->makeTicket($this->teamB);

        $this->assertSame([$a->id], $this->visibleIds($this->coordA));
    }

    public function test_coordinator_does_not_see_other_team_tickets_unless_explicitly_assigned_to_them(): void
    {
        $unassigned = $this->makeTicket($this->teamB);
        $assignedToOtherB = Ticket::factory()->forTeam($this->teamB)->assignedTo($this->empB1)->create();
        $assignedToCoordA = $this->makeTicket($this->teamB);
        $assignedToCoordA->assignments()->save(new Assignment(['user_id' => $this->coordA->id, 'role' => AssignmentRole::Colaborador]));

        $ids = $this->visibleIds($this->coordA);

        $this->assertSame([$assignedToCoordA->id], $ids);
        $this->assertNotContains($unassigned->id, $ids);
        $this->assertNotContains($assignedToOtherB->id, $ids);
    }

    public function test_employee_sees_created_assigned_and_team_bag_only(): void
    {
        $created = $this->makeTicket($this->teamA, $this->empA1);
        // Asignado a otro compañero del mismo equipo: no es bolsa ni suyo.
        $assignedToOther = Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)->assignedTo($this->empA2)->create();
        $bagOfTeam = Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)->create();
        $assignedToMe = Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)->assignedTo($this->empA1)->create();
        $otherTeamBag = $this->makeTicket($this->teamB);

        $ids = $this->visibleIds($this->empA1);

        $this->assertContains($created->id, $ids);
        $this->assertContains($bagOfTeam->id, $ids);
        $this->assertContains($assignedToMe->id, $ids);
        $this->assertNotContains($assignedToOther->id, $ids);
        $this->assertNotContains($otherTeamBag->id, $ids);
    }

    public function test_employee_keeps_seeing_tickets_they_created_but_not_foreign_ones_of_that_team(): void
    {
        $mine = $this->makeTicket($this->teamB, $this->empA1);
        $foreign = $this->makeTicket($this->teamB, $this->empB1);

        $ids = $this->visibleIds($this->empA1);

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_users_without_application_access_see_nothing(): void
    {
        $this->makeTicket($this->teamA);

        $pending = User::factory()->pending()->create();
        $inactive = User::factory()->empleado()->inactive()->create(['team_id' => $this->teamA->id]);
        $noRole = User::factory()->active()->create();

        $this->assertSame([], $this->visibleIds($pending));
        $this->assertSame([], $this->visibleIds($inactive));
        $this->assertSame([], $this->visibleIds($noRole));
    }

    public function test_overdue_is_a_calculation_not_a_status(): void
    {
        Carbon::setTestNow('2026-09-24 12:00:00');

        $overdue = Ticket::factory()->forTeam($this->teamA)->dueOn('2026-09-23')->create();
        $dueToday = Ticket::factory()->forTeam($this->teamA)->dueOn('2026-09-24')->create();
        $noDate = Ticket::factory()->forTeam($this->teamA)->create();
        $completedLate = Ticket::factory()->forTeam($this->teamA)->completed()->dueOn('2026-09-01')->create();
        $cancelledLate = Ticket::factory()->forTeam($this->teamA)->cancelled()->dueOn('2026-09-01')->create();
        $inReviewLate = Ticket::factory()->forTeam($this->teamA)->inReview()->dueOn('2026-09-01')->create();

        $ids = Ticket::query()->overdue()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$overdue->id, $inReviewLate->id], $ids);
        $this->assertTrue($overdue->fresh()->isOverdue());
        $this->assertFalse($dueToday->fresh()->isOverdue());
        $this->assertFalse($noDate->fresh()->isOverdue());
        $this->assertFalse($completedLate->fresh()->isOverdue());
        $this->assertFalse($cancelledLate->fresh()->isOverdue());
        $this->assertNotContains('overdue', TicketStatus::values());
    }

    public function test_overdue_uses_the_business_time_zone(): void
    {
        // 2026-09-25 03:00 UTC = 2026-09-24 22:00 en Cancun: "hoy" sigue siendo el 24.
        Carbon::setTestNow('2026-09-25 03:00:00');

        $dueYesterdayLocal = Ticket::factory()->forTeam($this->teamA)->dueOn('2026-09-23')->create();
        $dueTodayLocal = Ticket::factory()->forTeam($this->teamA)->dueOn('2026-09-24')->create();

        $ids = Ticket::query()->overdue()->pluck('id')->all();

        $this->assertSame([$dueYesterdayLocal->id], $ids);
        $this->assertNotContains($dueTodayLocal->id, $ids);
    }

    public function test_morph_map_uses_stable_aliases_and_keeps_legacy_class_names_for_users_and_teams(): void
    {
        $this->assertSame('ticket', (new Ticket)->getMorphClass());
        $this->assertSame('App\Models\User', (new User)->getMorphClass());
        $this->assertSame('App\Models\Team', (new Team)->getMorphClass());
        $this->assertArrayHasKey('activity', Relation::morphMap());
        $this->assertSame(MorphMap::aliases(), Relation::morphMap());
        $this->assertTrue(Relation::requiresMorphMap());
    }

    public function test_existing_users_keep_their_roles_under_the_enforced_morph_map(): void
    {
        $this->assertTrue($this->jefe->fresh()->hasRole('jefe_zona'));
        $this->assertSame('App\Models\User', DB::table('model_has_roles')->where('model_id', $this->jefe->id)->value('model_type'));
    }

    public function test_activity_log_subject_uses_the_ticket_alias(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->create();

        $this->assertDatabaseHas('activity_log', ['subject_type' => 'ticket', 'subject_id' => $ticket->id]);
        $this->assertInstanceOf(Ticket::class, Activity::query()->where('subject_type', 'ticket')->firstOrFail()->subject);
    }
}
