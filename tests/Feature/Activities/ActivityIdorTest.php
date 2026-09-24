<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Subtask;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * IDOR: cambiar el ID en la URL nunca da acceso a actividades, subtareas, comentarios ni adjuntos fuera
 * del alcance, ni cruzando entre tickets y actividades (que comparten tablas polimórficas).
 */
class ActivityIdorTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    private Activity $activity;

    private Subtask $subtask;

    private Attachment $attachment;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->buildScenario();

        $this->activity = $this->makeActivity($this->teamA, ['title' => 'Secreto del equipo A', 'status' => TicketStatus::InProgress], $this->empA1);
        $this->subtask = $this->makeSubtask($this->activity, 'Paso secreto');
        Comment::factory()->for($this->activity, 'commentable')->create(['body' => 'comentario secreto', 'user_id' => $this->empA1->id]);
        $this->attachment = Attachment::factory()->for($this->activity, 'attachable')->create(['user_id' => $this->empA1->id, 'path' => 'activities/'.$this->activity->id.'/secreto.pdf']);
        Storage::disk('local')->put($this->attachment->path, 'contenido secreto');
    }

    /**
     * @return array<string, callable(self, User): TestResponse>
     */
    private function everyRequest(): array
    {
        $id = $this->activity->id;
        $sub = $this->subtask->id;

        return [
            'show' => fn (self $t, User $u) => $t->signIn($u)->get("/activities/{$id}"),
            'edit form' => fn (self $t, User $u) => $t->signIn($u)->get("/activities/{$id}/edit"),
            'update' => fn (self $t, User $u) => $t->signIn($u)->put("/activities/{$id}", ['title' => 'Hack', 'description' => 'x', 'priority' => Priority::Low->value, 'responsible_id' => $u->id]),
            'delete' => fn (self $t, User $u) => $t->signIn($u)->delete("/activities/{$id}"),
            'transition' => fn (self $t, User $u) => $t->signIn($u)->post("/activities/{$id}/transition", ['status' => TicketStatus::InReview->value]),
            'cancel' => fn (self $t, User $u) => $t->signIn($u)->post("/activities/{$id}/transition", ['status' => TicketStatus::Cancelled->value]),
            'assign' => fn (self $t, User $u) => $t->signIn($u)->put("/activities/{$id}/assignments", ['responsible_id' => $u->id]),
            'comment' => fn (self $t, User $u) => $t->signIn($u)->post("/activities/{$id}/comments", ['body' => 'intruso']),
            'attach' => fn (self $t, User $u) => $t->signIn($u)->post("/activities/{$id}/attachments", ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]),
            'subtask add' => fn (self $t, User $u) => $t->signIn($u)->post("/activities/{$id}/subtasks", ['title' => 'intruso']),
            'subtask edit' => fn (self $t, User $u) => $t->signIn($u)->put("/activities/{$id}/subtasks/{$sub}", ['title' => 'intruso']),
            'subtask done' => fn (self $t, User $u) => $t->signIn($u)->post("/activities/{$id}/subtasks/{$sub}/done", ['done' => 1]),
            'subtask delete' => fn (self $t, User $u) => $t->signIn($u)->delete("/activities/{$id}/subtasks/{$sub}"),
            'download' => fn (self $t, User $u) => $t->signIn($u)->get(route('attachments.download', $this->attachment)),
            'delete attachment' => fn (self $t, User $u) => $t->signIn($u)->delete(route('attachments.destroy', $this->attachment)),
        ];
    }

    private function assertNothingChanged(): void
    {
        $fresh = $this->activity->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame('Secreto del equipo A', $fresh->title);
        $this->assertSame(TicketStatus::InProgress, $fresh->status);
        $this->assertSame([$this->empA1->id], $fresh->assignments()->pluck('user_id')->all());
        $this->assertSame(1, $fresh->comments()->count());
        $this->assertSame(1, $fresh->attachments()->count());
        $this->assertSame(['Paso secreto'], $fresh->subtasks()->pluck('title')->all());
        $this->assertFalse($this->subtask->fresh()->done);
        Storage::disk('local')->assertExists($this->attachment->path);
    }

    public function test_every_endpoint_answers_404_to_people_outside_the_scope_and_changes_nothing(): void
    {
        $outsiders = [
            'coordinator of another team' => $this->coordB,
            'employee of another team' => $this->empB1,
            'same-team employee who is not assigned' => $this->empA2,
        ];

        foreach ($outsiders as $who => $user) {
            foreach ($this->everyRequest() as $endpoint => $send) {
                $send($this, $user)->assertNotFound("{$endpoint} como {$who}");
            }
        }

        $this->assertNothingChanged();
    }

    public function test_the_assigned_employee_is_forbidden_from_manager_only_endpoints_and_changes_nothing(): void
    {
        $managerOnly = ['edit form', 'update', 'delete', 'cancel', 'assign', 'subtask add', 'subtask edit', 'subtask delete'];

        foreach ($this->everyRequest() as $endpoint => $send) {
            if (in_array($endpoint, $managerOnly, true)) {
                $send($this, $this->empA1)->assertForbidden("{$endpoint} como empleado asignado");
            }
        }

        $this->assertNothingChanged();
    }

    public function test_a_coordinator_reaches_an_out_of_team_activity_only_when_explicitly_assigned(): void
    {
        $this->assignTo($this->activity, $this->coordB);

        $this->signIn($this->coordB)->get("/activities/{$this->activity->id}")->assertOk();
        $this->signIn($this->coordB)->get(route('attachments.download', $this->attachment))->assertOk();
    }

    // --- Cruce entre tickets y actividades ----------------------------------------------------------------------------------------------

    public function test_a_ticket_attachment_is_not_downloadable_just_because_an_activity_with_the_same_id_is_visible(): void
    {
        // Ticket del equipo B con adjunto; la actividad de A tiene el mismo id numérico.
        $ticket = Ticket::factory()->forTeam($this->teamB)->createdBy($this->empB1)->create();
        $this->assertSame($this->activity->id, $ticket->id);
        $ticketAttachment = Attachment::factory()->for($ticket, 'attachable')->create(['user_id' => $this->empB1->id, 'path' => "tickets/{$ticket->id}/x.pdf"]);
        Storage::disk('local')->put($ticketAttachment->path, 'ticket secreto');

        // empA1 ve la ACTIVIDAD con ese id, pero no el TICKET del equipo B.
        $this->signIn($this->empA1)->get(route('attachments.download', $ticketAttachment))->assertNotFound();
        $this->signIn($this->empA1)->get("/tickets/{$ticket->id}")->assertNotFound();
        $this->signIn($this->empA1)->delete(route('attachments.destroy', $ticketAttachment))->assertNotFound();
    }

    public function test_an_activity_attachment_is_not_downloadable_just_because_a_ticket_with_the_same_id_is_visible(): void
    {
        // empB1 crea un ticket con el mismo id que la actividad de A: ve el ticket, no la actividad.
        $ticket = Ticket::factory()->forTeam($this->teamB)->createdBy($this->empB1)->create();
        $this->assertSame($this->activity->id, $ticket->id);

        $this->signIn($this->empB1)->get("/tickets/{$ticket->id}")->assertOk();
        $this->signIn($this->empB1)->get("/activities/{$this->activity->id}")->assertNotFound();
        $this->signIn($this->empB1)->get(route('attachments.download', $this->attachment))->assertNotFound();
    }

    public function test_ticket_routes_never_expose_activity_data_and_comments_stay_with_their_parent(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamB)->createdBy($this->empB1)->create(['title' => 'Ticket de B']);

        $response = $this->signIn($this->empB1)->get("/tickets/{$ticket->id}")->assertOk();
        $response->assertDontSee('comentario secreto')->assertDontSee('Secreto del equipo A')->assertDontSee('Paso secreto');
    }

    public function test_an_orphaned_attachment_whose_parent_is_gone_is_not_downloadable(): void
    {
        $this->activity->delete();

        $this->signIn($this->jefe)->get(route('attachments.download', $this->attachment))->assertNotFound();
    }
}
