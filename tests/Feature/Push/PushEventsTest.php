<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ChatMessageReceived;
use App\Notifications\WorkItemAssigned;
use App\Notifications\WorkItemRejected;
use App\Notifications\WorkItemStatusChanged;
use App\Services\AssignmentService;
use App\Services\ChatService;
use App\Services\TicketService;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\DatabaseTestCase;

class PushEventsTest extends DatabaseTestCase
{
    private Team $team;

    private User $jefe;

    private User $ana;

    private User $beto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $this->jefe = User::factory()->jefe()->create();
        $this->ana = User::factory()->empleado()->create(['team_id' => $this->team->id]);
        $this->beto = User::factory()->empleado()->create(['team_id' => $this->team->id]);
    }

    private function ticket(): Ticket
    {
        $ticket = Ticket::factory()->create(['team_id' => $this->team->id, 'created_by' => $this->jefe->id]);
        app(AssignmentService::class)->assign($this->jefe, $ticket, $this->ana->id, [$this->beto->id]);

        return $ticket;
    }

    public function test_assignment_notification_also_goes_by_web_push_with_a_relative_url(): void
    {
        $ticket = Ticket::factory()->create(['team_id' => $this->team->id, 'created_by' => $this->jefe->id]);
        app(AssignmentService::class)->assign($this->jefe, $ticket, $this->ana->id, []);

        $notification = new WorkItemAssigned('ticket', $ticket->id, $ticket->folio, $ticket->title, 'responsable', $this->jefe->name);

        $this->assertContains(WebPushChannel::class, $notification->via($this->ana));
        $message = $notification->toWebPush($this->ana, $notification)->toArray();
        $this->assertSame(route('tickets.show', $ticket->id, false), $message['data']['url']);
        $this->assertStringContainsString($ticket->folio, $message['body']);
    }

    public function test_status_change_notifies_participants_but_not_the_actor(): void
    {
        $ticket = $this->ticket();
        Notification::fake();

        app(TicketService::class)->transition($this->ana, $ticket, TicketStatus::InProgress);

        Notification::assertSentTo([$this->beto, $this->jefe], WorkItemStatusChanged::class);
        Notification::assertNotSentTo($this->ana, WorkItemStatusChanged::class);
    }

    public function test_rejection_sends_the_rejection_notice_and_not_a_duplicate_status_notice(): void
    {
        $ticket = $this->ticket();
        $service = app(TicketService::class);
        $service->transition($this->ana, $ticket, TicketStatus::InProgress);
        $service->transition($this->ana, $ticket->fresh(), TicketStatus::InReview);
        Notification::fake();

        $service->transition($this->jefe, $ticket->fresh(), TicketStatus::InProgress, 'Falta evidencia');

        Notification::assertSentTo($this->ana, WorkItemRejected::class);
        Notification::assertNotSentTo($this->ana, WorkItemStatusChanged::class);
    }

    public function test_status_notice_skips_inactive_users(): void
    {
        $ticket = $this->ticket();
        $this->beto->forceFill(['status' => UserStatus::Inactive])->save();
        Notification::fake();

        app(TicketService::class)->transition($this->ana, $ticket, TicketStatus::InProgress);

        Notification::assertNotSentTo($this->beto, WorkItemStatusChanged::class);
    }

    public function test_direct_message_notifies_only_the_other_person_without_the_text(): void
    {
        $conversation = app(ChatService::class)->startDirect($this->ana, $this->beto);
        Notification::fake();

        app(ChatService::class)->send($this->ana, $conversation, 'texto secreto');

        Notification::assertSentTo($this->beto, ChatMessageReceived::class, function (ChatMessageReceived $n): bool {
            $body = $n->toWebPush($this->beto, $n)->toArray()['body'];

            return ! str_contains($body, 'texto secreto') && str_contains($body, $this->ana->name);
        });
        Notification::assertNotSentTo($this->ana, ChatMessageReceived::class);
    }

    public function test_team_message_notifies_active_members_except_the_sender(): void
    {
        $inactive = User::factory()->empleado()->create(['team_id' => $this->team->id, 'status' => UserStatus::Inactive]);
        $this->team->members()->syncWithoutDetaching([$this->ana->id, $this->beto->id, $inactive->id]);
        $channel = Conversation::factory()->team($this->team)->create();
        Notification::fake();

        app(ChatService::class)->send($this->ana, $channel, 'Hola equipo');

        Notification::assertSentTo($this->beto, ChatMessageReceived::class);
        Notification::assertNotSentTo($this->ana, ChatMessageReceived::class);
        Notification::assertNotSentTo($inactive, ChatMessageReceived::class);
    }
}
