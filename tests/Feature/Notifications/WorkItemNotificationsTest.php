<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Models\Activity;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\WorkItemCommented;
use App\Notifications\WorkItemRejected;
use App\Services\ActivityService;
use App\Services\AssignmentService;
use App\Services\CommentService;
use App\Services\TicketService;
use Tests\DatabaseTestCase;

class WorkItemNotificationsTest extends DatabaseTestCase
{
    private User $jefe;

    private User $ana;

    private User $beto;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();

        $team = Team::factory()->create();
        $this->jefe = User::factory()->jefe()->create();
        $this->ana = User::factory()->empleado()->create(['team_id' => $team->id]);
        $this->beto = User::factory()->empleado()->create(['team_id' => $team->id]);
        $this->activity = Activity::factory()->create(['team_id' => $team->id, 'created_by' => $this->jefe->id, 'title' => 'Reporte semanal']);

        app(AssignmentService::class)->assign($this->jefe, $this->activity, $this->ana->id, [$this->beto->id]);
        $this->ana->notifications()->delete();
        $this->beto->notifications()->delete();
    }

    public function test_comment_notifies_assignees_and_creator_but_not_the_author(): void
    {
        app(CommentService::class)->add($this->ana, $this->activity, 'Avance listo');

        $this->assertCount(0, $this->ana->fresh()->notifications);
        $this->assertCount(1, $this->beto->fresh()->notifications);
        $this->assertCount(1, $this->jefe->fresh()->notifications);

        $data = $this->beto->fresh()->notifications->first()->data;
        $this->assertSame(WorkItemCommented::TYPE, $data['type']);
        $this->assertSame($this->activity->folio, $data['folio']);
        $this->assertSame($this->ana->name, $data['by']);
        $this->assertArrayNotHasKey('body', $data);
    }

    public function test_comment_skips_inactive_users(): void
    {
        $this->beto->forceFill(['status' => UserStatus::Inactive])->save();

        app(CommentService::class)->add($this->ana, $this->activity, 'Hola');

        $this->assertCount(0, $this->beto->fresh()->notifications);
    }

    private function assignedTicket(): Ticket
    {
        $ticket = Ticket::factory()->create(['team_id' => $this->activity->team_id, 'created_by' => $this->jefe->id]);
        app(AssignmentService::class)->assign($this->jefe, $ticket, $this->beto->id, [$this->ana->id]);
        $this->beto->notifications()->delete();
        $this->ana->notifications()->delete();

        return $ticket;
    }

    public function test_ticket_comment_notifies_assignees_and_creator_but_not_the_author(): void
    {
        $ticket = $this->assignedTicket();

        app(CommentService::class)->add($this->ana, $ticket, 'Hola');

        $this->assertCount(0, $this->ana->fresh()->notifications);
        $this->assertCount(1, $this->jefe->fresh()->notifications);
        $data = $this->beto->fresh()->notifications->first()->data;
        $this->assertSame(WorkItemCommented::TYPE, $data['type']);
        $this->assertSame('ticket', $data['kind']);
        $this->assertSame($ticket->folio, $data['folio']);
    }

    public function test_rejecting_a_ticket_notifies_assignees_and_opens_the_ticket(): void
    {
        $ticket = $this->assignedTicket();
        $ticket->forceFill(['status' => TicketStatus::InReview])->save();

        app(TicketService::class)->transition($this->jefe, $ticket, TicketStatus::InProgress, 'Falta evidencia');

        $notification = $this->beto->fresh()->notifications->first();
        $this->assertSame(WorkItemRejected::TYPE, $notification->data['type']);
        $this->assertCount(1, $this->ana->fresh()->notifications);
        $this->assertCount(0, $this->jefe->fresh()->notifications);

        $this->signIn($this->beto)
            ->get(route('notifications.open', $notification->id))
            ->assertRedirect(route('tickets.show', $ticket));
    }

    public function test_ticket_transitions_other_than_rejection_do_not_notify(): void
    {
        $ticket = $this->assignedTicket();

        app(TicketService::class)->transition($this->jefe, $ticket, TicketStatus::InProgress);

        $this->assertCount(0, $this->beto->fresh()->notifications);
    }

    public function test_rejecting_an_activity_notifies_assignees(): void
    {
        $this->activity->forceFill(['status' => TicketStatus::InReview])->save();

        app(ActivityService::class)->transition($this->jefe, $this->activity, TicketStatus::InProgress, 'Falta evidencia');

        foreach ([$this->ana, $this->beto] as $user) {
            $notifications = $user->fresh()->notifications;
            $this->assertCount(1, $notifications);
            $this->assertSame(WorkItemRejected::TYPE, $notifications->first()->data['type']);
        }
        $this->assertCount(0, $this->jefe->fresh()->notifications);
    }

    public function test_other_transitions_do_not_send_rejection_notice(): void
    {
        $this->activity->forceFill(['status' => TicketStatus::Pending])->save();

        app(ActivityService::class)->transition($this->jefe, $this->activity, TicketStatus::InProgress);

        $this->assertCount(0, $this->ana->fresh()->notifications);
    }

    public function test_opening_the_notification_redirects_to_the_activity(): void
    {
        app(CommentService::class)->add($this->ana, $this->activity, 'Hola');
        $notification = $this->beto->fresh()->notifications->first();

        $this->signIn($this->beto)
            ->get(route('notifications.open', $notification->id))
            ->assertRedirect(route('activities.show', $this->activity));

        $this->signIn($this->beto)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee($this->activity->folio);
    }
}
