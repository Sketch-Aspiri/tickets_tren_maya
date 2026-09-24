<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\AssignmentRole;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Subtask;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

class SubtaskTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $this->activity = $this->makeActivity($this->teamA, ['status' => TicketStatus::InProgress], $this->empA1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function url(?Subtask $subtask = null, string $suffix = '', ?Activity $activity = null): string
    {
        $base = '/activities/'.($activity ?? $this->activity)->id.'/subtasks';

        return $subtask === null ? $base : "{$base}/{$subtask->id}{$suffix}";
    }

    private function markDone(User $actor, Subtask $subtask, bool $done = true): TestResponse
    {
        return $this->signIn($actor)->post($this->url($subtask, '/done'), ['done' => $done ? '1' : '0']);
    }

    /**
     * @return list<string>
     */
    private function events(): array
    {
        return ActivityLogEntry::query()->where('subject_type', 'activity')->where('subject_id', $this->activity->id)
            ->where('event', 'like', 'subtask_%')->orderBy('id')->pluck('event')->all();
    }

    // --- Crear / editar / eliminar (gestores) ---------------------------------------------------------------------------------

    public function test_coordinator_adds_a_subtask_with_an_optional_assignee_and_it_is_audited(): void
    {
        $this->signIn($this->coordA)->post($this->url(), ['title' => 'Recolectar datos', 'assigned_to' => $this->empA2->id])
            ->assertRedirect(route('activities.show', $this->activity).'#subtareas')->assertSessionHas('status', 'subtask-added');
        $this->post($this->url(), ['title' => 'Sin responsable'])->assertSessionHasNoErrors();

        $subtasks = $this->activity->subtasks()->orderBy('id')->get();
        $this->assertSame(['Recolectar datos', 'Sin responsable'], $subtasks->pluck('title')->all());
        $this->assertSame([$this->empA2->id, null], $subtasks->pluck('assigned_to')->all());
        $this->assertSame([false, false], $subtasks->pluck('done')->all());
        $this->assertSame(['subtask_added', 'subtask_added'], $this->events());

        $entry = ActivityLogEntry::query()->where('event', 'subtask_added')->orderBy('id')->firstOrFail();
        $this->assertSame($this->coordA->id, $entry->causer_id);
        $this->assertSame('Recolectar datos', $entry->properties['title']);
    }

    public function test_subtask_input_is_validated_and_protected_columns_are_ignored(): void
    {
        $this->signIn($this->coordA);

        $this->post($this->url(), ['title' => ''])->assertInvalid(['title']);
        $this->post($this->url(), ['title' => str_repeat('a', 256)])->assertInvalid(['title']);
        $this->post($this->url(), ['title' => 'Ok', 'assigned_to' => 9999])->assertInvalid(['assigned_to']);

        $other = $this->makeActivity($this->teamA);
        $this->post($this->url(), ['title' => 'Ok', 'done' => 1, 'done_at' => now()->toDateTimeString(), 'activity_id' => $other->id]);

        $subtask = Subtask::query()->firstOrFail();
        $this->assertSame($this->activity->id, $subtask->activity_id);
        $this->assertFalse($subtask->done);
        $this->assertNull($subtask->done_at);
    }

    public function test_the_assignee_must_be_an_active_person_assigned_to_the_activity_or_in_its_team(): void
    {
        $inactive = User::factory()->empleado()->inactive()->create(['team_id' => $this->teamA->id]);
        $pending = User::factory()->pending()->create();
        $foreignCollaborator = User::factory()->empleado()->create(['team_id' => $this->teamB->id]);
        $this->assignTo($this->activity, $foreignCollaborator, AssignmentRole::Colaborador);

        $this->signIn($this->coordA);
        foreach ([$this->empB1, $inactive, $pending] as $candidate) {
            $this->post($this->url(), ['title' => 'x', 'assigned_to' => $candidate->id])->assertSessionHas('error');
        }
        $this->assertSame(0, $this->activity->subtasks()->count());

        // Del equipo de la actividad (aunque no esté asignado) y asignado de otro equipo: válidos.
        $this->post($this->url(), ['title' => 'del equipo', 'assigned_to' => $this->empA2->id])->assertSessionHasNoErrors();
        $this->post($this->url(), ['title' => 'colaborador externo', 'assigned_to' => $foreignCollaborator->id])->assertSessionHasNoErrors();
        $this->assertSame(2, $this->activity->subtasks()->count());
    }

    public function test_a_subtask_assignee_can_open_the_activity_even_if_not_assigned_to_it(): void
    {
        $this->signIn($this->coordA)->post($this->url(), ['title' => 'Para A2', 'assigned_to' => $this->empA2->id]);

        $this->signIn($this->empA2)->get("/activities/{$this->activity->id}")->assertOk();
    }

    public function test_the_number_of_subtasks_per_activity_is_capped(): void
    {
        $max = (int) config('tickets.max_subtasks');
        Subtask::factory()->count($max)->for($this->activity)->create();

        $this->signIn($this->coordA)->from('/x')->post($this->url(), ['title' => 'una más'])->assertSessionHas('error');
        $this->assertSame($max, $this->activity->subtasks()->count());
    }

    public function test_coordinator_edits_title_and_assignee_and_deletes(): void
    {
        $subtask = $this->makeSubtask($this->activity, 'Antes');

        $this->signIn($this->coordA)->put($this->url($subtask), ['title' => 'Después', 'assigned_to' => $this->empA2->id])
            ->assertRedirect(route('activities.show', $this->activity).'#subtareas')->assertSessionHas('status', 'subtask-updated');
        $this->assertSame('Después', $subtask->fresh()->title);
        $this->assertSame($this->empA2->id, $subtask->fresh()->assigned_to);

        $this->put($this->url($subtask), ['title' => 'Sin dueño', 'assigned_to' => null]);
        $this->assertNull($subtask->fresh()->assigned_to);

        $this->delete($this->url($subtask))->assertSessionHas('status', 'subtask-removed');
        $this->assertNull(Subtask::query()->find($subtask->id));
        $this->assertSame(['subtask_updated', 'subtask_updated', 'subtask_removed'], $this->events());
    }

    public function test_employees_and_other_teams_cannot_manage_subtasks(): void
    {
        $subtask = $this->makeSubtask($this->activity, 'Intacta');

        $this->signIn($this->empA1);
        $this->post($this->url(), ['title' => 'x'])->assertForbidden();
        $this->put($this->url($subtask), ['title' => 'x'])->assertForbidden();
        $this->delete($this->url($subtask))->assertForbidden();

        $this->signIn($this->coordB);
        $this->post($this->url(), ['title' => 'x'])->assertNotFound();
        $this->put($this->url($subtask), ['title' => 'x'])->assertNotFound();
        $this->delete($this->url($subtask))->assertNotFound();
        $this->markDone($this->coordB, $subtask)->assertNotFound();

        $this->assertSame(['Intacta'], $this->activity->subtasks()->pluck('title')->all());
    }

    public function test_a_subtask_cannot_be_reached_through_another_activity(): void
    {
        $other = $this->makeActivity($this->teamA);
        $foreign = $this->makeSubtask($other, 'De otra actividad');

        $this->signIn($this->coordA);
        $this->put($this->url($foreign), ['title' => 'Hack'])->assertNotFound();
        $this->delete($this->url($foreign))->assertNotFound();
        $this->post($this->url($foreign, '/done'), ['done' => 1])->assertNotFound();

        $this->assertSame('De otra actividad', $foreign->fresh()->title);
        $this->assertFalse($foreign->fresh()->done);
    }

    // --- Marcar / desmarcar ----------------------------------------------------------------------------------------------------

    public function test_the_subtask_assignee_marks_and_unmarks_and_done_at_follows(): void
    {
        Carbon::setTestNow('2026-09-24 12:00:00');
        $subtask = $this->makeSubtask($this->activity, 'Mía', false, $this->empA2);

        $this->markDone($this->empA2, $subtask)->assertRedirect(route('activities.show', $this->activity).'#subtareas')->assertSessionHas('status', 'subtask-updated');
        $this->assertTrue($subtask->fresh()->done);
        $this->assertSame('2026-09-24 12:00:00', $subtask->fresh()->done_at->toDateTimeString());

        $this->markDone($this->empA2, $subtask, false)->assertSessionHasNoErrors();
        $this->assertFalse($subtask->fresh()->done);
        $this->assertNull($subtask->fresh()->done_at);

        $this->assertSame(['subtask_done', 'subtask_undone'], $this->events());
    }

    public function test_the_activity_responsible_marks_any_subtask_but_a_bystander_does_not(): void
    {
        $peers = $this->makeSubtask($this->activity, 'De A2', false, $this->empA2);
        $free = $this->makeSubtask($this->activity, 'Libre');
        $this->makeSubtask($this->activity, 'Para que A2 vea la actividad', false, $this->empA2);

        $this->markDone($this->empA1, $peers)->assertSessionHasNoErrors();
        $this->markDone($this->empA1, $free)->assertSessionHasNoErrors();
        $this->assertSame(2, $this->activity->subtasks()->where('done', true)->count());

        $locked = $this->makeSubtask($this->activity, 'De A1', false, $this->empA1);
        $this->markDone($this->empA2, $locked)->assertForbidden();
        $this->assertFalse($locked->fresh()->done);
    }

    public function test_employees_out_of_scope_get_404_when_marking(): void
    {
        $subtask = $this->makeSubtask($this->activity, 'Ajena');

        $this->markDone($this->empB1, $subtask)->assertNotFound();
    }

    public function test_marking_input_is_validated(): void
    {
        $subtask = $this->makeSubtask($this->activity);

        $this->signIn($this->coordA)->post($this->url($subtask, '/done'), [])->assertInvalid(['done']);
        $this->post($this->url($subtask, '/done'), ['done' => 'quizá'])->assertInvalid(['done']);
    }

    public function test_marking_is_idempotent(): void
    {
        $subtask = $this->makeSubtask($this->activity, 'x', true);

        $this->markDone($this->coordA, $subtask)->assertSessionHasNoErrors();

        $this->assertTrue($subtask->fresh()->done);
        $this->assertSame([], $this->events(), 'sin cambio real no se registra evento');
    }

    // --- Estados finales y plantillas ---------------------------------------------------------------------------------------------

    public function test_final_activities_reject_every_subtask_change(): void
    {
        foreach ([TicketStatus::Completed, TicketStatus::Cancelled] as $status) {
            $activity = $this->makeActivity($this->teamA, ['status' => $status], $this->empA1);
            $subtask = $this->makeSubtask($activity, 'Congelada');

            $this->signIn($this->coordA)->from('/x');
            $this->post($this->url(activity: $activity), ['title' => 'nueva'])->assertSessionHas('error', __('activities.errors.closed_subtasks'));
            $this->put($this->url($subtask, activity: $activity), ['title' => 'cambio'])->assertSessionHas('error', __('activities.errors.closed_subtasks'));
            $this->post($this->url($subtask, '/done', $activity), ['done' => 1])->assertSessionHas('error', __('activities.errors.closed_subtasks'));
            $this->signIn($this->empA1)->post($this->url($subtask, '/done', $activity), ['done' => 1])->assertSessionHas('error', __('activities.errors.closed_subtasks'));
            $this->signIn($this->coordA)->delete($this->url($subtask, activity: $activity))->assertSessionHas('error', __('activities.errors.closed_subtasks'));

            $this->assertSame(['Congelada'], $activity->subtasks()->pluck('title')->all());
            $this->assertFalse($subtask->fresh()->done);
        }
    }

    public function test_reopening_a_final_activity_allows_changes_again(): void
    {
        $activity = $this->makeActivity($this->teamA, ['status' => TicketStatus::Cancelled], $this->empA1);
        $subtask = $this->makeSubtask($activity);

        $this->signIn($this->coordA)->post("/activities/{$activity->id}/transition", ['status' => 'pending', 'comment' => 'reabierta']);
        $this->post($this->url($subtask, '/done', $activity), ['done' => 1])->assertSessionHasNoErrors();

        $this->assertTrue($subtask->fresh()->done);
    }

    public function test_subtasks_of_a_template_can_be_managed_but_not_marked(): void
    {
        $template = $this->makeActivity($this->teamA, ['recurrence_rule' => ['frequency' => 'daily', 'interval' => 1], 'start_date' => '2030-01-01']);

        $this->signIn($this->coordA)->post($this->url(activity: $template), ['title' => 'Paso fijo'])->assertSessionHasNoErrors();
        $subtask = $template->subtasks()->firstOrFail();

        $this->from('/x')->post($this->url($subtask, '/done', $template), ['done' => 1])->assertSessionHas('error', __('activities.errors.template_subtask_done'));
        $this->assertFalse($subtask->fresh()->done);
    }

    // --- Avance ---------------------------------------------------------------------------------------------------------------------

    public function test_progress_follows_marking_and_deleting(): void
    {
        $a = $this->makeSubtask($this->activity, 'a');
        $b = $this->makeSubtask($this->activity, 'b');
        $c = $this->makeSubtask($this->activity, 'c');
        $this->assertSame(0, $this->activity->fresh()->progressPercent());

        $this->markDone($this->coordA, $a);
        $this->assertSame(33, $this->activity->fresh()->progressPercent());

        $this->markDone($this->coordA, $b);
        $this->assertSame(66, $this->activity->fresh()->progressPercent());

        $this->signIn($this->coordA)->delete($this->url($c));
        $this->assertSame(100, $this->activity->fresh()->progressPercent());

        $this->markDone($this->coordA, $a, false);
        $this->assertSame(50, $this->activity->fresh()->progressPercent());
    }

    public function test_progress_is_shown_with_text_in_the_detail_page(): void
    {
        $this->makeSubtask($this->activity, 'a', true);
        $this->makeSubtask($this->activity, 'b');

        $this->signIn($this->coordA)->get("/activities/{$this->activity->id}")->assertOk()->assertSee('50 %', false)->assertSee('role="progressbar"', false);
    }
}
