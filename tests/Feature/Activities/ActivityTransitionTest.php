<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\User;
use App\Services\ActivityService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * La actividad usa la MISMA máquina de estados que el ticket (TicketStatus + StatusTransitioner).
 */
class ActivityTransitionTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

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

    private function move(User $actor, Activity $activity, TicketStatus $to, ?string $comment = null): TestResponse
    {
        return $this->signIn($actor)->post("/activities/{$activity->id}/transition", array_filter([
            'status' => $to->value,
            'comment' => $comment,
        ], fn ($value) => $value !== null));
    }

    private function activityFor(User $assignee, TicketStatus $status): Activity
    {
        return $this->makeActivity($this->teamA, ['status' => $status], $assignee);
    }

    public function test_full_flow_employee_to_review_then_coordinator_approves_with_history_and_audit(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');
        $activity = $this->activityFor($this->empA1, TicketStatus::Pending);

        $this->move($this->empA1, $activity, TicketStatus::InProgress)->assertRedirect(route('activities.show', $activity))->assertSessionHas('status', 'activity-status-changed');
        $this->move($this->empA1, $activity, TicketStatus::InReview, 'Listo para revisar')->assertSessionHasNoErrors();

        $this->assertSame(TicketStatus::InReview, $activity->fresh()->status);
        $this->assertNull($activity->fresh()->completed_at);

        Carbon::setTestNow('2026-09-25 11:30:00');
        $this->move($this->coordA, $activity, TicketStatus::Completed, 'Aprobado');

        $fresh = $activity->fresh();
        $this->assertSame(TicketStatus::Completed, $fresh->status);
        $this->assertSame('2026-09-25 11:30:00', $fresh->completed_at->toDateTimeString());

        $rows = $activity->statusHistories()->orderBy('id')->get();
        $this->assertSame([TicketStatus::Pending, TicketStatus::InProgress, TicketStatus::InReview], $rows->pluck('from_status')->all());
        $this->assertSame([TicketStatus::InProgress, TicketStatus::InReview, TicketStatus::Completed], $rows->pluck('to_status')->all());
        $this->assertSame([$this->empA1->id, $this->empA1->id, $this->coordA->id], $rows->pluck('user_id')->all());
        $this->assertSame([null, 'Listo para revisar', 'Aprobado'], $rows->pluck('comment')->all());

        $audit = ActivityLogEntry::query()->where('subject_type', 'activity')->where('subject_id', $activity->id)->where('event', 'status_changed')->orderBy('id')->get();
        $this->assertCount(3, $audit);
        $this->assertSame($this->coordA->id, $audit->last()->causer_id);
        $this->assertSame('activities', $audit->last()->log_name);
        $this->assertSame('in_review', $audit->last()->attribute_changes['old']['status']);
        $this->assertSame('completed', $audit->last()->attribute_changes['attributes']['status']);
    }

    public function test_rejecting_and_reopening_require_a_comment(): void
    {
        $review = $this->activityFor($this->empA1, TicketStatus::InReview);

        $this->from('/activities')->move($this->coordA, $review, TicketStatus::InProgress)->assertSessionHas('error', __('activities.errors.comment_required'));
        $this->move($this->coordA, $review, TicketStatus::InProgress, '   ')->assertSessionHas('error', __('activities.errors.comment_required'));
        $this->assertSame(TicketStatus::InReview, $review->fresh()->status);
        $this->assertSame(0, $review->statusHistories()->count());

        $this->move($this->coordA, $review, TicketStatus::InProgress, 'Falta evidencia')->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::InProgress, $review->fresh()->status);

        $completed = $this->makeActivity($this->teamA, ['status' => TicketStatus::Completed]);
        $completed->forceFill(['completed_at' => now()])->save();
        $this->move($this->coordA, $completed, TicketStatus::Pending)->assertSessionHas('error', __('activities.errors.comment_required'));
        $this->move($this->coordA, $completed, TicketStatus::Pending, 'Se reabre')->assertSessionHasNoErrors();

        $reopened = $completed->fresh();
        $this->assertSame(TicketStatus::Pending, $reopened->status);
        $this->assertNull($reopened->completed_at);
    }

    /**
     * @return array<string, array{0: TicketStatus, 1: TicketStatus}>
     */
    public static function invalidEdges(): array
    {
        return [
            'pending to review' => [TicketStatus::Pending, TicketStatus::InReview],
            'pending to completed' => [TicketStatus::Pending, TicketStatus::Completed],
            'in progress to completed' => [TicketStatus::InProgress, TicketStatus::Completed],
            'in progress to pending' => [TicketStatus::InProgress, TicketStatus::Pending],
            'review to pending' => [TicketStatus::InReview, TicketStatus::Pending],
            'completed to in progress' => [TicketStatus::Completed, TicketStatus::InProgress],
            'cancelled to review' => [TicketStatus::Cancelled, TicketStatus::InReview],
            'same state' => [TicketStatus::Pending, TicketStatus::Pending],
        ];
    }

    #[DataProvider('invalidEdges')]
    public function test_the_manager_cannot_take_an_activity_along_a_missing_edge(TicketStatus $from, TicketStatus $to): void
    {
        $activity = $this->activityFor($this->empA1, $from);

        $this->from('/activities')->move($this->jefe, $activity, $to, 'motivo')->assertSessionHas('error');

        $this->assertSame($from, $activity->fresh()->status);
        $this->assertSame(0, $activity->statusHistories()->count());
    }

    public function test_employee_can_never_complete_cancel_or_reopen_in_any_state(): void
    {
        foreach (TicketStatus::cases() as $from) {
            $activity = $this->activityFor($this->empA1, $from);

            foreach ([TicketStatus::Completed, TicketStatus::Cancelled, TicketStatus::Pending] as $to) {
                $this->move($this->empA1, $activity, $to, 'x')->assertForbidden();
            }

            if ($from === TicketStatus::InReview) {
                $this->move($this->empA1, $activity, TicketStatus::InProgress, 'rechazo propio')->assertForbidden();
            }

            $this->assertSame($from, $activity->fresh()->status);
        }
    }

    public function test_employee_cannot_move_activities_that_are_not_theirs_and_other_teams_see_404(): void
    {
        $activity = $this->activityFor($this->empA1, TicketStatus::Pending);

        $this->move($this->empA2, $activity, TicketStatus::InProgress)->assertNotFound();
        $this->move($this->coordB, $activity, TicketStatus::Cancelled, 'x')->assertNotFound();
        $this->assertSame(TicketStatus::Pending, $activity->fresh()->status);
    }

    public function test_the_service_reauthorizes_against_the_fresh_state(): void
    {
        $activity = $this->activityFor($this->empA1, TicketStatus::Pending);
        $stale = Activity::query()->findOrFail($activity->id);

        // Mientras el empleado "tenía abierta" la pantalla, un gestor la canceló.
        $activity->forceFill(['status' => TicketStatus::Completed])->save();

        $this->expectException(AuthorizationException::class);

        app(ActivityService::class)->transition($this->empA1, $stale, TicketStatus::InProgress);
    }

    public function test_cancelling_is_for_managers_of_the_team_and_needs_no_comment(): void
    {
        $activity = $this->activityFor($this->empA1, TicketStatus::InProgress);

        $this->move($this->coordA, $activity, TicketStatus::Cancelled)->assertSessionHasNoErrors();

        $this->assertSame(TicketStatus::Cancelled, $activity->fresh()->status);
    }

    public function test_status_input_is_validated(): void
    {
        $activity = $this->activityFor($this->empA1, TicketStatus::Pending);

        $this->signIn($this->coordA)->post("/activities/{$activity->id}/transition", ['status' => 'overdue'])->assertInvalid(['status']);
        $this->post("/activities/{$activity->id}/transition", [])->assertInvalid(['status']);
        $this->post("/activities/{$activity->id}/transition", ['status' => 'in_progress', 'comment' => str_repeat('a', 2001)])->assertInvalid(['comment']);
    }

    // --- Subtareas pendientes ------------------------------------------------------------------------------------------------------

    public function test_pending_subtasks_block_sending_to_review_and_approving(): void
    {
        $activity = $this->activityFor($this->empA1, TicketStatus::InProgress);
        $subtask = $this->makeSubtask($activity, 'Falta esto');

        $this->from('/activities')->move($this->empA1, $activity, TicketStatus::InReview)->assertSessionHas('error', __('activities.errors.pending_subtasks', ['count' => 1]));
        $this->assertSame(TicketStatus::InProgress, $activity->fresh()->status);

        $subtask->forceFill(['done' => true, 'done_at' => now()])->save();
        $this->move($this->empA1, $activity, TicketStatus::InReview)->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::InReview, $activity->fresh()->status);

        // Una subtarea agregada durante la revisión también bloquea la aprobación, pero no el rechazo ni la cancelación.
        $this->makeSubtask($activity, 'Nueva');
        $this->move($this->coordA, $activity, TicketStatus::Completed, 'ok')->assertSessionHas('error', __('activities.errors.pending_subtasks', ['count' => 1]));
        $this->move($this->coordA, $activity, TicketStatus::InProgress, 'rechazo')->assertSessionHasNoErrors();
        $this->move($this->coordA, $activity, TicketStatus::Cancelled)->assertSessionHasNoErrors();
    }

    public function test_activities_without_subtasks_move_freely(): void
    {
        $activity = $this->activityFor($this->empA1, TicketStatus::InProgress);

        $this->move($this->empA1, $activity, TicketStatus::InReview)->assertSessionHasNoErrors();
        $this->move($this->coordA, $activity, TicketStatus::Completed)->assertSessionHasNoErrors();
    }

    // --- Plantillas de recurrencia -------------------------------------------------------------------------------------------------

    public function test_a_template_can_only_be_cancelled_and_reopened_never_worked(): void
    {
        $template = $this->makeActivity($this->teamA, ['recurrence_rule' => ['frequency' => 'daily', 'interval' => 1], 'start_date' => '2030-01-01']);

        foreach ([TicketStatus::InProgress, TicketStatus::Completed] as $to) {
            $this->from('/activities')->move($this->coordA, $template, $to, 'x')->assertSessionHas('error', __('activities.errors.template_transition'));
        }
        $this->assertSame(TicketStatus::Pending, $template->fresh()->status);

        $this->move($this->coordA, $template, TicketStatus::Cancelled)->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::Cancelled, $template->fresh()->status);

        $this->move($this->coordA, $template, TicketStatus::Pending, 'Se reanuda la serie')->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::Pending, $template->fresh()->status);
    }

    public function test_available_transitions_are_filtered_by_policy_and_template_rules(): void
    {
        $service = app(ActivityService::class);
        $activity = $this->activityFor($this->empA1, TicketStatus::InProgress);
        $template = $this->makeActivity($this->teamA, ['recurrence_rule' => ['frequency' => 'daily', 'interval' => 1], 'start_date' => '2030-01-01']);

        $this->assertSame([TicketStatus::InReview], $service->availableTransitions($this->empA1, $activity));
        $this->assertEqualsCanonicalizing([TicketStatus::InReview, TicketStatus::Cancelled], $service->availableTransitions($this->coordA, $activity));
        $this->assertSame([TicketStatus::Cancelled], $service->availableTransitions($this->coordA, $template));
    }
}
