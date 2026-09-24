<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\AssignmentRole;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * Las actividades se asignan SIEMPRE de forma explícita (sin bolsa ni "tomar"): un responsable único y
 * colaboradores; reglas de AssignmentService compartidas con tickets.
 */
class ActivityAssignmentTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $this->activity = $this->makeActivity($this->teamA, [], $this->empA1);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assign(User $actor, array $data, ?Activity $activity = null): TestResponse
    {
        return $this->signIn($actor)->put('/activities/'.($activity ?? $this->activity)->id.'/assignments', $data);
    }

    /**
     * @return list<array{0: int, 1: string}>
     */
    private function assignments(?Activity $activity = null): array
    {
        return ($activity ?? $this->activity)->assignments()->orderBy('id')->get()
            ->map(fn ($assignment): array => [$assignment->user_id, $assignment->role->value])
            ->all();
    }

    public function test_coordinator_reassigns_with_one_responsible_and_several_collaborators(): void
    {
        $third = User::factory()->empleado()->create(['team_id' => $this->teamA->id]);

        $this->assign($this->coordA, ['responsible_id' => $this->empA2->id, 'collaborator_ids' => [$this->empA1->id, $third->id]])
            ->assertRedirect(route('activities.show', $this->activity))->assertSessionHas('status', 'activity-assigned');

        $this->assertEqualsCanonicalizing(
            [[$this->empA2->id, 'responsable'], [$this->empA1->id, 'colaborador'], [$third->id, 'colaborador']],
            $this->assignments(),
        );
        $this->assertSame(1, $this->activity->assignments()->where('role', AssignmentRole::Responsable->value)->count());
    }

    public function test_the_responsible_is_never_duplicated_as_collaborator_and_repeats_are_rejected(): void
    {
        $this->assign($this->coordA, ['responsible_id' => $this->empA1->id, 'collaborator_ids' => [$this->empA1->id, $this->empA2->id]])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([[$this->empA1->id, 'responsable'], [$this->empA2->id, 'colaborador']], $this->assignments());

        $this->assign($this->coordA, ['responsible_id' => $this->empA1->id, 'collaborator_ids' => [$this->empA2->id, $this->empA2->id]])->assertInvalid(['collaborator_ids.0']);
    }

    public function test_a_responsible_is_required(): void
    {
        $this->assign($this->coordA, ['collaborator_ids' => [$this->empA2->id]])->assertInvalid(['responsible_id']);
        $this->assertSame([[$this->empA1->id, 'responsable']], $this->assignments());
    }

    public function test_coordinator_can_only_assign_active_members_of_own_team(): void
    {
        $inactive = User::factory()->empleado()->inactive()->create(['team_id' => $this->teamA->id]);
        $pending = User::factory()->pending()->create();

        foreach ([$this->empB1, $inactive, $pending] as $candidate) {
            $this->from('/x')->assign($this->coordA, ['responsible_id' => $candidate->id])->assertSessionHas('error');
            $this->assign($this->coordA, ['responsible_id' => $this->empA1->id, 'collaborator_ids' => [$candidate->id]])->assertSessionHas('error');
        }

        $this->assertSame([[$this->empA1->id, 'responsable']], $this->assignments());
    }

    public function test_jefe_can_assign_active_people_of_any_team_but_not_inactive_ones(): void
    {
        $inactive = User::factory()->empleado()->inactive()->create(['team_id' => $this->teamA->id]);

        $this->assign($this->jefe, ['responsible_id' => $this->empB1->id, 'collaborator_ids' => [$this->empA2->id]])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([[$this->empB1->id, 'responsable'], [$this->empA2->id, 'colaborador']], $this->assignments());

        $this->assign($this->jefe, ['responsible_id' => $inactive->id])->assertSessionHas('error');
    }

    public function test_at_most_the_configured_number_of_collaborators(): void
    {
        $many = User::factory()->count(11)->empleado()->create(['team_id' => $this->teamA->id])->pluck('id')->all();

        $this->assign($this->coordA, ['responsible_id' => $this->empA1->id, 'collaborator_ids' => $many])->assertInvalid(['collaborator_ids']);
    }

    public function test_only_managers_of_the_scope_can_assign(): void
    {
        $this->assign($this->empA1, ['responsible_id' => $this->empA1->id])->assertForbidden();
        $this->assign($this->empA2, ['responsible_id' => $this->empA2->id])->assertNotFound();
        $this->assign($this->coordB, ['responsible_id' => $this->empB1->id])->assertNotFound();

        $this->assertSame([[$this->empA1->id, 'responsable']], $this->assignments());
    }

    public function test_final_activities_are_not_assigned_until_reopened(): void
    {
        $done = $this->makeActivity($this->teamA, ['status' => TicketStatus::Completed], $this->empA1);

        $this->from('/x')->assign($this->coordA, ['responsible_id' => $this->empA2->id], $done)->assertSessionHas('error', __('activities.errors.closed_assignment'));
        $this->assertSame([[$this->empA1->id, 'responsable']], $this->assignments($done));
    }

    public function test_assignment_changes_are_audited_with_before_and_after(): void
    {
        $this->assign($this->coordA, ['responsible_id' => $this->empA2->id]);

        $entry = ActivityLogEntry::query()->where('subject_type', 'activity')->where('subject_id', $this->activity->id)->where('event', 'reassigned')->firstOrFail();

        $this->assertSame($this->coordA->id, $entry->causer_id);
        $this->assertSame('activities', $entry->log_name);
        $this->assertSame($this->empA1->id, $entry->attribute_changes['old']['assignments'][0]['user_id']);
        $this->assertSame($this->empA2->id, $entry->attribute_changes['attributes']['assignments'][0]['user_id']);
    }

    public function test_there_is_no_bag_take_or_unassign_route_for_activities(): void
    {
        $this->signIn($this->empA1)->post("/activities/{$this->activity->id}/take")->assertNotFound();
        $this->signIn($this->coordA)->delete("/activities/{$this->activity->id}/assignments")->assertStatus(405);
    }
}
