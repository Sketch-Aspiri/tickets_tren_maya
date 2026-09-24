<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\AssignmentRole;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Subtask;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * Matriz de la ActivityPolicy / SubtaskPolicy por rol (permiso Spatie + alcance). Fuera de alcance = 404
 * (no revela que existe); dentro de alcance sin permiso = 403.
 */
class ActivityPolicyTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        // Actividad del equipo A con empA1 responsable y empA2 colaborador de solo lectura de equipo (no asignado).
        $this->activity = $this->makeActivity($this->teamA, [], $this->empA1);
    }

    private function outcome(?User $user, string $ability, mixed $arguments): string
    {
        $response = Gate::forUser($user)->inspect($ability, $arguments);

        if ($response->allowed()) {
            return 'allow';
        }

        return $response->status() === 404 ? '404' : 'deny';
    }

    /**
     * @return array<string, User>
     */
    private function actors(): array
    {
        return [
            'jefe' => $this->jefe,
            'coordA' => $this->coordA,
            'coordB' => $this->coordB,
            'empA1' => $this->empA1,
            'empA2' => $this->empA2,
            'empB1' => $this->empB1,
            'pending' => User::factory()->pending()->create(),
            'inactive' => User::factory()->empleado()->inactive()->create(['team_id' => $this->teamA->id]),
            'norole' => User::factory()->active()->create(),
        ];
    }

    /**
     * @param  array<string, string>  $expected
     */
    private function assertMatrix(string $ability, mixed $arguments, array $expected): void
    {
        foreach ($this->actors() as $label => $user) {
            $this->assertSame($expected[$label], $this->outcome($user, $ability, $arguments), "{$ability} como {$label}");
        }
    }

    public function test_view_follows_the_read_scope(): void
    {
        $this->assertMatrix('view', $this->activity, [
            'jefe' => 'allow', 'coordA' => 'allow', 'coordB' => '404', 'empA1' => 'allow', 'empA2' => '404',
            'empB1' => '404', 'pending' => '404', 'inactive' => '404', 'norole' => '404',
        ]);
    }

    public function test_view_any_and_create_are_management_abilities(): void
    {
        $expected = ['jefe' => 'allow', 'coordA' => 'allow', 'coordB' => 'allow', 'empA1' => 'deny', 'empA2' => 'deny', 'empB1' => 'deny', 'pending' => 'deny', 'inactive' => 'deny', 'norole' => 'deny'];

        $this->assertMatrix('viewAny', Activity::class, $expected);
        $this->assertMatrix('create', Activity::class, $expected);
    }

    public function test_update_delete_and_assign_are_for_managers_of_the_scope_only(): void
    {
        $expected = ['jefe' => 'allow', 'coordA' => 'allow', 'coordB' => '404', 'empA1' => 'deny', 'empA2' => '404', 'empB1' => '404', 'pending' => '404', 'inactive' => '404', 'norole' => '404'];

        foreach (['update', 'delete', 'assign'] as $ability) {
            $this->assertMatrix($ability, $this->activity, $expected);
        }
    }

    public function test_final_activities_cannot_be_updated_by_anyone(): void
    {
        $done = $this->makeActivity($this->teamA, ['status' => TicketStatus::Completed]);

        $this->assertSame('deny', $this->outcome($this->jefe, 'update', $done));
        $this->assertSame('deny', $this->outcome($this->coordA, 'update', $done));
    }

    public function test_comment_and_attach_need_view_scope_and_work_permission(): void
    {
        $expected = ['jefe' => 'allow', 'coordA' => 'allow', 'coordB' => '404', 'empA1' => 'allow', 'empA2' => '404', 'empB1' => '404', 'pending' => '404', 'inactive' => '404', 'norole' => '404'];

        $this->assertMatrix('comment', $this->activity, $expected);
        $this->assertMatrix('attach', $this->activity, $expected);
    }

    public function test_employee_advances_only_up_to_review_and_only_what_is_theirs(): void
    {
        $this->assertSame('allow', $this->outcome($this->empA1, 'transition', [$this->activity, TicketStatus::InProgress]));
        $this->assertSame('deny', $this->outcome($this->empA1, 'transition', [$this->activity, TicketStatus::Completed]));
        $this->assertSame('deny', $this->outcome($this->empA1, 'transition', [$this->activity, TicketStatus::Cancelled]));
        $this->assertSame('deny', $this->outcome($this->empA1, 'transition', [$this->activity, TicketStatus::Pending]));
        $this->assertSame('404', $this->outcome($this->empA2, 'transition', [$this->activity, TicketStatus::InProgress]));
    }

    public function test_only_managers_approve_reject_cancel_and_reopen(): void
    {
        $review = $this->makeActivity($this->teamA, ['status' => TicketStatus::InReview], $this->empA1);
        $cancelled = $this->makeActivity($this->teamA, ['status' => TicketStatus::Cancelled], $this->empA1);

        foreach ([$this->jefe, $this->coordA] as $manager) {
            $this->assertSame('allow', $this->outcome($manager, 'transition', [$review, TicketStatus::Completed]));
            $this->assertSame('allow', $this->outcome($manager, 'transition', [$review, TicketStatus::InProgress]));
            $this->assertSame('allow', $this->outcome($manager, 'transition', [$review, TicketStatus::Cancelled]));
            $this->assertSame('allow', $this->outcome($manager, 'transition', [$cancelled, TicketStatus::Pending]));
        }

        foreach ([[$review, TicketStatus::Completed], [$review, TicketStatus::InProgress], [$cancelled, TicketStatus::Pending]] as $args) {
            $this->assertSame('deny', $this->outcome($this->empA1, 'transition', $args));
        }

        $this->assertSame('404', $this->outcome($this->coordB, 'transition', [$review, TicketStatus::Completed]));
    }

    public function test_subtask_management_is_for_managers_and_marking_for_assignee_or_responsible(): void
    {
        $mine = $this->makeSubtask($this->activity, 'mía', false, $this->empA1);
        $ofPeer = $this->makeSubtask($this->activity, 'de otro', false, $this->empA2);
        $unassigned = $this->makeSubtask($this->activity, 'libre');

        // Gestionar (crear/editar/borrar): jefe y coordinador del alcance.
        foreach (['create' => [Subtask::class, $this->activity], 'update' => $mine, 'delete' => $mine] as $ability => $arguments) {
            $this->assertSame('allow', $this->outcome($this->jefe, $ability, $arguments), "{$ability} jefe");
            $this->assertSame('allow', $this->outcome($this->coordA, $ability, $arguments), "{$ability} coordA");
            $this->assertSame('deny', $this->outcome($this->empA1, $ability, $arguments), "{$ability} empleado");
            $this->assertSame('404', $this->outcome($this->coordB, $ability, $arguments), "{$ability} coordB");
        }

        // Marcar: responsable de la actividad (cualquier subtarea), asignado a la subtarea (la suya), gestores.
        $this->assertSame('allow', $this->outcome($this->empA1, 'markDone', $mine));
        $this->assertSame('allow', $this->outcome($this->empA1, 'markDone', $ofPeer), 'el responsable de la actividad marca cualquier subtarea');
        $this->assertSame('allow', $this->outcome($this->empA1, 'markDone', $unassigned));
        $this->assertSame('allow', $this->outcome($this->jefe, 'markDone', $ofPeer));
        $this->assertSame('allow', $this->outcome($this->coordA, 'markDone', $ofPeer));
        // empA2 tiene una subtarea suya y ve la actividad, pero no marca las ajenas ni las libres.
        $this->assertSame('allow', $this->outcome($this->empA2, 'markDone', $ofPeer));
        $this->assertSame('deny', $this->outcome($this->empA2, 'markDone', $mine));
        $this->assertSame('deny', $this->outcome($this->empA2, 'markDone', $unassigned));
        $this->assertSame('404', $this->outcome($this->empB1, 'markDone', $mine));
    }

    public function test_a_collaborator_who_is_not_responsible_only_marks_their_own_subtasks(): void
    {
        $this->assignTo($this->activity, $this->empA2, AssignmentRole::Colaborador);
        $mine = $this->makeSubtask($this->activity, 'suya', false, $this->empA2);
        $other = $this->makeSubtask($this->activity, 'de A1', false, $this->empA1);

        $this->assertSame('allow', $this->outcome($this->empA2, 'markDone', $mine));
        $this->assertSame('deny', $this->outcome($this->empA2, 'markDone', $other));
    }
}
