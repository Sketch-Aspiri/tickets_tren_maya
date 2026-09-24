<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTicketScenario;
use Tests\DatabaseTestCase;

/**
 * IDOR: cambiar el ID en la URL nunca da acceso a tickets fuera del alcance del usuario.
 * Coordinador del equipo A vs. tickets del equipo B, y empleado vs. tickets ajenos.
 */
class TicketIdorTest extends DatabaseTestCase
{
    use BuildsTicketScenario;

    private Ticket $ticketOfB;

    private Attachment $attachmentOfB;

    private Ticket $ticketAssignedToPeer;

    private Attachment $attachmentOfPeer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->buildScenario();

        $this->ticketOfB = Ticket::factory()->forTeam($this->teamB)->createdBy($this->empB1)->inProgress()->create(['title' => 'Secreto del equipo B']);
        $this->attachmentOfB = Attachment::factory()->for($this->ticketOfB, 'attachable')->create(['user_id' => $this->empB1->id]);
        Storage::disk('local')->put($this->attachmentOfB->path, 'contenido secreto B');

        $this->ticketAssignedToPeer = Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)->assignedTo($this->empA2)->create(['title' => 'Trabajo de A2']);
        $this->attachmentOfPeer = Attachment::factory()->for($this->ticketAssignedToPeer, 'attachable')->create(['user_id' => $this->empA2->id]);
        Storage::disk('local')->put($this->attachmentOfPeer->path, 'contenido de A2');
    }

    /**
     * @return array<string, callable(self, User, Ticket, Attachment): TestResponse>
     */
    private function everyRequest(): array
    {
        $pdf = fn (): UploadedFile => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf');

        return [
            'show' => fn (self $t, User $u, Ticket $k) => $t->signIn($u)->get("/tickets/{$k->id}"),
            'edit form' => fn (self $t, User $u, Ticket $k) => $t->signIn($u)->get("/tickets/{$k->id}/edit"),
            'update' => fn (self $t, User $u, Ticket $k) => $t->signIn($u)->put("/tickets/{$k->id}", ['title' => 'Hack', 'description' => 'x', 'priority' => Priority::Low->value]),
            'delete' => fn (self $t, User $u, Ticket $k) => $t->signIn($u)->delete("/tickets/{$k->id}"),
            'transition' => fn (self $t, User $u, Ticket $k) => $t->signIn($u)->post("/tickets/{$k->id}/transition", ['status' => TicketStatus::InReview->value]),
            'cancel' => fn (self $t, User $u, Ticket $k) => $t->signIn($u)->post("/tickets/{$k->id}/transition", ['status' => TicketStatus::Cancelled->value]),
            'assign' => fn (self $t, User $u, Ticket $k) => $t->signIn($u)->put("/tickets/{$k->id}/assignments", ['responsible_id' => $u->id]),
            'unassign' => fn (self $t, User $u, Ticket $k) => $t->signIn($u)->delete("/tickets/{$k->id}/assignments"),
            'take' => fn (self $t, User $u, Ticket $k) => $t->signIn($u)->post("/tickets/{$k->id}/take"),
            'comment' => fn (self $t, User $u, Ticket $k) => $t->signIn($u)->post("/tickets/{$k->id}/comments", ['body' => 'intruso']),
            'attach' => fn (self $t, User $u, Ticket $k) => $t->signIn($u)->post("/tickets/{$k->id}/attachments", ['file' => $pdf()]),
            'download' => fn (self $t, User $u, Ticket $k, Attachment $a) => $t->signIn($u)->get(route('attachments.download', $a)),
            'delete attachment' => fn (self $t, User $u, Ticket $k, Attachment $a) => $t->signIn($u)->delete(route('attachments.destroy', $a)),
        ];
    }

    private function assertNothingChanged(Ticket $ticket, Attachment $attachment): void
    {
        $fresh = $ticket->fresh();

        $this->assertNotNull($fresh, 'el ticket sigue existiendo');
        $this->assertSame($ticket->title, $fresh->title);
        $this->assertSame($ticket->status, $fresh->status);
        $this->assertSame($ticket->assignments()->count(), $fresh->assignments()->count());
        $this->assertSame(0, Comment::query()->where('body', 'intruso')->count());
        $this->assertSame(1, Attachment::query()->where('attachable_id', $ticket->id)->count());
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_coordinator_of_team_a_gets_404_on_every_route_for_a_team_b_ticket(): void
    {
        foreach ($this->everyRequest() as $name => $request) {
            $response = $request($this, $this->coordA, $this->ticketOfB, $this->attachmentOfB);

            $this->assertSame(404, $response->getStatusCode(), "coordinador A -> ticket de B via '{$name}' debio ser 404");
            $response->assertDontSee('Secreto del equipo B');
        }

        $this->assertNothingChanged($this->ticketOfB, $this->attachmentOfB);
    }

    public function test_coordinator_of_team_b_gets_404_on_every_route_for_a_team_a_ticket(): void
    {
        foreach ($this->everyRequest() as $name => $request) {
            $response = $request($this, $this->coordB, $this->ticketAssignedToPeer, $this->attachmentOfPeer);

            $this->assertSame(404, $response->getStatusCode(), "coordinador B -> ticket de A via '{$name}' debio ser 404");
        }

        $this->assertNothingChanged($this->ticketAssignedToPeer, $this->attachmentOfPeer);
    }

    public function test_employee_of_another_team_gets_404_on_every_route(): void
    {
        foreach ($this->everyRequest() as $name => $request) {
            $response = $request($this, $this->empA1, $this->ticketOfB, $this->attachmentOfB);

            $this->assertSame(404, $response->getStatusCode(), "empleado A -> ticket de B via '{$name}' debio ser 404");
        }

        $this->assertNothingChanged($this->ticketOfB, $this->attachmentOfB);
    }

    public function test_employee_gets_404_on_every_route_for_a_same_team_ticket_assigned_to_a_peer(): void
    {
        foreach ($this->everyRequest() as $name => $request) {
            $response = $request($this, $this->empA1, $this->ticketAssignedToPeer, $this->attachmentOfPeer);

            $this->assertSame(404, $response->getStatusCode(), "empleado A1 -> ticket de A2 via '{$name}' debio ser 404");
        }

        $this->assertNothingChanged($this->ticketAssignedToPeer, $this->attachmentOfPeer);
    }

    public function test_the_same_requests_reach_the_ticket_for_the_jefe(): void
    {
        $this->signIn($this->jefe)->get("/tickets/{$this->ticketOfB->id}")->assertOk()->assertSee('Secreto del equipo B');
        $this->signIn($this->jefe)->get(route('attachments.download', $this->attachmentOfB))->assertOk();
    }

    public function test_ids_in_the_url_that_do_not_belong_to_the_user_do_not_leak_through_the_list_or_pending_views(): void
    {
        $this->signIn($this->coordA)->get('/tickets')->assertOk()->assertDontSee('Secreto del equipo B');
        $this->signIn($this->empA1)->get('/tickets')->assertOk()->assertDontSee('Trabajo de A2')->assertDontSee('Secreto del equipo B');
        $this->signIn($this->empA1)->get('/tickets/pending')->assertOk()->assertDontSee('Trabajo de A2');
    }

    public function test_assignee_ids_in_the_request_cannot_pull_users_from_other_teams_for_a_coordinator(): void
    {
        $bag = Ticket::factory()->forTeam($this->teamA)->createdBy($this->coordA)->create();

        $this->signIn($this->coordA)->put("/tickets/{$bag->id}/assignments", ['responsible_id' => $this->empB1->id])
            ->assertSessionHas('error', __('tickets.errors.assignee_out_of_team'));

        $this->assertSame(0, $bag->assignments()->count());
    }

    public function test_route_ids_that_do_not_exist_or_are_soft_deleted_are_404_for_everyone(): void
    {
        $gone = Ticket::factory()->forTeam($this->teamA)->create();
        $gone->delete();

        foreach ([$this->jefe, $this->coordA] as $actor) {
            $this->signIn($actor)->get('/tickets/999999')->assertNotFound();
            $this->signIn($actor)->get("/tickets/{$gone->id}")->assertNotFound();
            $this->signIn($actor)->post("/tickets/{$gone->id}/comments", ['body' => 'x'])->assertNotFound();
            $this->signIn($actor)->get('/attachments/999999')->assertNotFound();
        }
    }

    public function test_a_coordinator_moved_to_another_team_loses_access_to_the_old_team_tickets(): void
    {
        $ticketOfA = Ticket::factory()->forTeam($this->teamA)->create();
        $this->signIn($this->coordA)->get("/tickets/{$ticketOfA->id}")->assertOk();

        $this->coordA->forceFill(['team_id' => $this->teamB->id])->save();

        $this->signIn($this->coordA->fresh())->get("/tickets/{$ticketOfA->id}")->assertNotFound();
        $this->signIn($this->coordA->fresh())->get("/tickets/{$this->ticketOfB->id}")->assertOk();
    }
}
