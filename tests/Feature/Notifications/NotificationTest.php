<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\WorkItemAssigned;
use App\Services\AssignmentService;
use App\Services\ChatService;
use Tests\DatabaseTestCase;

class NotificationTest extends DatabaseTestCase
{
    private Team $team;

    private User $jefe;

    private User $ana;

    private User $beto;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $this->jefe = User::factory()->jefe()->create();
        $this->ana = User::factory()->empleado()->create(['team_id' => $this->team->id]);
        $this->beto = User::factory()->empleado()->create(['team_id' => $this->team->id]);
        $this->ticket = Ticket::factory()->create(['team_id' => $this->team->id, 'created_by' => $this->jefe->id, 'title' => 'Impresora rota']);
    }

    private function assign(User $actor, User $responsible, array $collaborators = []): void
    {
        app(AssignmentService::class)->assign($actor, $this->ticket, $responsible->id, array_map(fn (User $u): int => $u->id, $collaborators));
    }

    public function test_new_assignees_get_a_notification_but_not_the_actor(): void
    {
        $this->assign($this->jefe, $this->ana, [$this->beto]);

        $this->assertCount(1, $this->ana->notifications);
        $this->assertCount(1, $this->beto->notifications);
        $this->assertCount(0, $this->jefe->notifications);

        $data = $this->ana->notifications->first()->data;
        $this->assertSame(WorkItemAssigned::TYPE, $data['type']);
        $this->assertSame('ticket', $data['kind']);
        $this->assertSame($this->ticket->folio, $data['folio']);
        $this->assertSame('responsable', $data['role']);
    }

    public function test_reassigning_only_notifies_people_added(): void
    {
        $this->assign($this->jefe, $this->ana);
        $this->assign($this->jefe, $this->ana, [$this->beto]);

        $this->assertCount(1, $this->ana->fresh()->notifications);
        $this->assertCount(1, $this->beto->fresh()->notifications);
    }

    public function test_summary_counts_alerts_and_unread_chat_messages(): void
    {
        $this->assign($this->jefe, $this->ana);
        $conversation = app(ChatService::class)->startDirect($this->beto, $this->ana);
        $this->signIn($this->beto)->postJson(route('chat.messages.store', $conversation), ['body' => 'hola'])->assertCreated();

        $this->signIn($this->ana)->getJson(route('notifications.summary'))
            ->assertOk()
            ->assertJsonPath('data.alerts', 1)
            ->assertJsonPath('data.chat', 1)
            ->assertJsonPath('data.unread', 2);
    }

    public function test_index_lists_alerts_and_unread_conversations(): void
    {
        $this->assign($this->jefe, $this->ana);
        $conversation = app(ChatService::class)->startDirect($this->beto, $this->ana);
        $this->signIn($this->beto)->postJson(route('chat.messages.store', $conversation), ['body' => 'hola']);

        $this->signIn($this->ana)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee($this->ticket->folio)
            ->assertSee('Impresora rota')
            ->assertSee($this->beto->name);
    }

    public function test_opening_marks_as_read_and_redirects_to_the_ticket(): void
    {
        $this->assign($this->jefe, $this->ana);
        $notification = $this->ana->notifications()->firstOrFail();

        $this->signIn($this->ana)->get(route('notifications.open', $notification->id))
            ->assertRedirect(route('tickets.show', $this->ticket));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_someone_elses_notification_is_404_and_stays_unread(): void
    {
        $this->assign($this->jefe, $this->ana);
        $notification = $this->ana->notifications()->firstOrFail();

        $this->signIn($this->beto)->get(route('notifications.open', $notification->id))->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_read_all_only_touches_own_notifications(): void
    {
        $this->assign($this->jefe, $this->ana, [$this->beto]);

        $this->signIn($this->ana)->post(route('notifications.read-all'))->assertRedirect(route('notifications.index'));

        $this->assertSame(0, $this->ana->unreadNotifications()->count());
        $this->assertSame(1, $this->beto->unreadNotifications()->count());
    }

    public function test_guest_and_inactive_users_cannot_reach_notifications(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
        $this->get(route('notifications.summary'), ['Accept' => 'application/json'])->assertUnauthorized();
    }
}
