<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Models\Comment;
use App\Models\Ticket;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsTicketScenario;
use Tests\DatabaseTestCase;

class TicketCommentTest extends DatabaseTestCase
{
    use BuildsTicketScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    public function test_everyone_who_can_see_a_ticket_can_comment_and_it_is_audited(): void
    {
        $ticket = $this->makeTicket($this->teamA, $this->empA1);

        foreach ([$this->empA1, $this->empA2, $this->coordA, $this->jefe] as $actor) {
            $this->signIn($actor)->post("/tickets/{$ticket->id}/comments", ['body' => "  Comentario de {$actor->name}  "])
                ->assertRedirect()
                ->assertSessionHas('status', 'comment-added');
        }

        $this->assertSame(4, $ticket->comments()->count());
        $comment = $ticket->comments()->orderBy('id')->first();
        $this->assertSame('Comentario de Emp A1', $comment->body, 'se recortan los espacios');
        $this->assertSame($this->empA1->id, $comment->user_id);

        $activity = Activity::query()->where('subject_type', 'ticket')->where('event', 'comment_added')->firstOrFail();
        $this->assertSame($this->empA1->id, $activity->causer_id);
        $this->assertSame($comment->id, $activity->properties['comment_id']);
    }

    public function test_comment_html_is_stored_as_text_and_always_rendered_escaped(): void
    {
        $ticket = $this->makeTicket($this->teamA, $this->empA1);
        $payload = '<script>alert("xss")</script><img src=x onerror=alert(1)> {{ 7*7 }} {!! "x" !!}';

        $this->signIn($this->empA1)->post("/tickets/{$ticket->id}/comments", ['body' => $payload])->assertSessionHasNoErrors();

        $this->assertSame($payload, Comment::query()->firstOrFail()->body, 'no se altera ni interpreta al guardar');

        $html = $this->signIn($this->coordA)->get("/tickets/{$ticket->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('{{ 7*7 }}', $html, 'las llaves de Blade no se evaluan');
    }

    public function test_body_is_required_and_limited(): void
    {
        $ticket = $this->makeTicket($this->teamA, $this->empA1);
        $actor = $this->signIn($this->empA1);

        $actor->post("/tickets/{$ticket->id}/comments", [])->assertInvalid('body');
        $actor->post("/tickets/{$ticket->id}/comments", ['body' => '   '])->assertInvalid('body');
        $actor->post("/tickets/{$ticket->id}/comments", ['body' => ['a']])->assertInvalid('body');
        $actor->post("/tickets/{$ticket->id}/comments", ['body' => str_repeat('a', 2001)])->assertInvalid('body');
        $actor->post("/tickets/{$ticket->id}/comments", ['body' => str_repeat('a', 2000)])->assertValid();

        $this->assertSame(1, $ticket->comments()->count());
    }

    public function test_spoofed_author_and_extra_fields_are_ignored(): void
    {
        $ticket = $this->makeTicket($this->teamA, $this->empA1);

        $this->signIn($this->empA1)->post("/tickets/{$ticket->id}/comments", [
            'body' => 'Hola',
            'user_id' => $this->jefe->id,
            'id' => 777,
            'commentable_id' => 999,
            'commentable_type' => 'App\Models\User',
        ])->assertSessionHasNoErrors();

        $comment = Comment::query()->firstOrFail();
        $this->assertSame($this->empA1->id, $comment->user_id);
        $this->assertNotSame(777, $comment->id);
        $this->assertSame($ticket->id, $comment->commentable_id);
        $this->assertSame('ticket', $comment->commentable_type);
    }

    public function test_users_who_cannot_see_the_ticket_get_404_and_nothing_is_saved(): void
    {
        $ticket = $this->makeTicket($this->teamA, $this->empA1);
        $assignedToPeer = Ticket::factory()->forTeam($this->teamA)->assignedTo($this->empA2)->create();

        $this->signIn($this->coordB)->post("/tickets/{$ticket->id}/comments", ['body' => 'intruso'])->assertNotFound();
        $this->signIn($this->empB1)->post("/tickets/{$ticket->id}/comments", ['body' => 'intruso'])->assertNotFound();
        $this->signIn($this->empA1)->post("/tickets/{$assignedToPeer->id}/comments", ['body' => 'intruso'])->assertNotFound();

        $this->assertSame(0, Comment::query()->count());
    }

    public function test_comments_are_rate_limited(): void
    {
        config(['tickets.rate_limits.ticket_comment_per_minute' => 2]);
        $ticket = $this->makeTicket($this->teamA, $this->empA1);
        $actor = $this->signIn($this->empA1);

        $actor->post("/tickets/{$ticket->id}/comments", ['body' => 'uno'])->assertRedirect();
        $actor->post("/tickets/{$ticket->id}/comments", ['body' => 'dos'])->assertRedirect();
        $actor->post("/tickets/{$ticket->id}/comments", ['body' => 'tres'])->assertStatus(429);

        $this->assertSame(2, Comment::query()->count());
    }

    public function test_comment_form_is_shown_only_when_allowed(): void
    {
        $ticket = $this->makeTicket($this->teamA, $this->empA1);

        $this->signIn($this->empA1)->get("/tickets/{$ticket->id}")->assertSee(__('tickets.comment.submit'));
    }
}
