<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Enums\ConversationType;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\DatabaseTestCase;

class ChatTest extends DatabaseTestCase
{
    private Team $teamA;

    private Team $teamB;

    private User $ana;

    private User $beto;

    private User $carla;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamA = Team::factory()->create();
        $this->teamB = Team::factory()->create();
        $this->ana = User::factory()->empleado()->inTeams($this->teamA)->create(['team_id' => $this->teamA->id]);
        $this->beto = User::factory()->empleado()->create(['team_id' => $this->teamB->id]);
        $this->carla = User::factory()->empleado()->create(['team_id' => $this->teamB->id]);
    }

    private function direct(User $a, User $b): Conversation
    {
        return app(ChatService::class)->startDirect($a, $b);
    }

    // ---- Policy / alcance ----

    public function test_direct_conversation_is_visible_only_to_its_participants(): void
    {
        $conversation = $this->direct($this->ana, $this->beto);

        $this->assertTrue(Gate::forUser($this->ana)->allows('view', $conversation));
        $this->assertTrue(Gate::forUser($this->beto)->allows('view', $conversation));
        $this->assertFalse(Gate::forUser($this->carla)->allows('view', $conversation));

        $this->signIn($this->carla)->get(route('chat.show', $conversation))->assertNotFound();
    }

    public function test_jefe_and_administrador_cannot_read_other_peoples_direct_chats(): void
    {
        $conversation = $this->direct($this->ana, $this->beto);

        foreach ([User::factory()->jefe()->create(), User::factory()->administrador()->create()] as $admin) {
            $this->signIn($admin)->get(route('chat.show', $conversation))->assertNotFound();
        }
    }

    public function test_team_channel_is_visible_only_to_team_members_including_multi_team(): void
    {
        $channel = Conversation::factory()->team($this->teamB)->create();
        $multi = User::factory()->empleado()->inTeams($this->teamA, $this->teamB)->create(['team_id' => $this->teamA->id]);

        $this->assertTrue(Gate::forUser($this->beto)->allows('view', $channel));
        $this->assertTrue(Gate::forUser($multi)->allows('view', $channel));
        $this->assertFalse(Gate::forUser($this->ana)->allows('view', $channel));
    }

    public function test_pending_inactive_and_roleless_users_have_no_access(): void
    {
        $conversation = $this->direct($this->ana, $this->beto);

        foreach ([User::factory()->pending()->create(), User::factory()->inactive()->create(), User::factory()->active()->create()] as $user) {
            $this->assertFalse(Gate::forUser($user)->allows('viewAny', Conversation::class));
            $this->assertFalse(Gate::forUser($user)->allows('view', $conversation));
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('chat.index'))->assertRedirect(route('login'));
    }

    // ---- Conversaciones ----

    public function test_start_direct_is_idempotent_and_symmetric(): void
    {
        $first = $this->direct($this->ana, $this->beto);
        $second = $this->direct($this->beto, $this->ana);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Conversation::query()->where('type', ConversationType::Direct->value)->count());
        $this->assertCount(2, $first->participants);
    }

    public function test_cannot_start_chat_with_self_or_inactive_user(): void
    {
        $this->signIn($this->ana)->postJson(route('chat.direct'), ['user_id' => $this->ana->id])->assertStatus(422);
        $this->signIn($this->ana)->postJson(route('chat.direct'), ['user_id' => User::factory()->inactive()->create()->id])->assertStatus(422);
        $this->assertSame(0, Conversation::query()->count());
    }

    public function test_start_direct_redirects_to_the_conversation(): void
    {
        $response = $this->signIn($this->ana)->post(route('chat.direct'), ['user_id' => $this->beto->id]);

        $conversation = Conversation::query()->firstOrFail();
        $response->assertRedirect(route('chat.show', $conversation));
    }

    public function test_index_creates_team_channel_lazily_for_each_team_of_the_user(): void
    {
        $this->signIn($this->ana)->get(route('chat.index'))->assertOk()->assertSee($this->teamA->name);

        $this->assertSame(1, Conversation::query()->where('team_id', $this->teamA->id)->count());
        $this->assertSame(0, Conversation::query()->where('team_id', $this->teamB->id)->count());
    }

    // ---- Mensajes ----

    public function test_participant_can_send_and_other_polls_only_new_messages(): void
    {
        $conversation = $this->direct($this->ana, $this->beto);

        $sent = $this->signIn($this->ana)
            ->postJson(route('chat.messages.store', $conversation), ['body' => 'Hola Beto'])
            ->assertCreated()
            ->assertJsonPath('success', true);
        $firstId = $sent->json('data.last_id');

        $this->signIn($this->ana)->postJson(route('chat.messages.store', $conversation), ['body' => 'Segundo']);

        $poll = $this->signIn($this->beto)->getJson(route('chat.messages.index', [$conversation, 'after' => $firstId]))->assertOk();
        $this->assertSame(1, $poll->json('data.count'));
        $this->assertStringContainsString('Segundo', $poll->json('data.html'));
        $this->assertStringNotContainsString('Hola Beto', $poll->json('data.html'));
    }

    public function test_outsider_cannot_send_or_poll_and_gets_404(): void
    {
        $conversation = $this->direct($this->ana, $this->beto);

        $this->signIn($this->carla)->postJson(route('chat.messages.store', $conversation), ['body' => 'x'])->assertNotFound();
        $this->signIn($this->carla)->getJson(route('chat.messages.index', [$conversation, 'after' => 0]))->assertNotFound();
        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_body_validation(): void
    {
        $conversation = $this->direct($this->ana, $this->beto);
        $url = route('chat.messages.store', $conversation);

        $this->signIn($this->ana)->postJson($url, [])->assertJsonValidationErrors('body');
        $this->signIn($this->ana)->postJson($url, ['body' => str_repeat('a', 2001)])->assertJsonValidationErrors('body');
        $this->signIn($this->ana)->postJson($url, ['body' => ['a']])->assertJsonValidationErrors('body');
        $this->signIn($this->ana)->postJson($url, ['body' => '   '])->assertStatus(422);
        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_extra_fields_are_ignored_on_send(): void
    {
        $conversation = $this->direct($this->ana, $this->beto);

        $this->signIn($this->ana)->postJson(route('chat.messages.store', $conversation), [
            'body' => 'hola',
            'user_id' => $this->beto->id,
            'conversation_id' => 999,
            'id' => 999,
        ])->assertCreated();

        $message = ChatMessage::query()->firstOrFail();
        $this->assertSame($this->ana->id, $message->user_id);
        $this->assertSame($conversation->id, $message->conversation_id);
    }

    public function test_html_is_stored_literally_and_escaped_on_output(): void
    {
        $conversation = $this->direct($this->ana, $this->beto);

        $response = $this->signIn($this->ana)
            ->postJson(route('chat.messages.store', $conversation), ['body' => '<script>alert(1)</script> <b>x</b>'])
            ->assertCreated();

        $this->assertSame('<script>alert(1)</script> <b>x</b>', ChatMessage::query()->firstOrFail()->body);
        $this->assertStringNotContainsString('<script>', $response->json('data.html'));
        $this->assertStringContainsString('&lt;script&gt;', $response->json('data.html'));
    }

    public function test_folios_link_only_when_reader_can_see_the_record(): void
    {
        $ticket = Ticket::factory()->create(['team_id' => $this->teamA->id, 'folio' => 'TM-2026-0001', 'created_by' => $this->ana->id]);
        $hidden = Ticket::factory()->create(['team_id' => $this->teamB->id, 'folio' => 'TM-2026-0002', 'created_by' => $this->beto->id]);
        $conversation = $this->direct($this->ana, $this->beto);

        $this->signIn($this->beto)->postJson(route('chat.messages.store', $conversation), ['body' => 'ver TM-2026-0001 y TM-2026-0002']);

        $html = $this->signIn($this->ana)->getJson(route('chat.messages.index', [$conversation, 'after' => 0]))->json('data.html');

        $this->assertStringContainsString('href="'.route('tickets.show', $ticket).'"', $html);
        $this->assertStringNotContainsString(route('tickets.show', $hidden), $html);
        $this->assertStringContainsString('TM-2026-0002', $html);
    }

    // ---- No leidos ----

    public function test_unread_counts_and_mark_read(): void
    {
        $conversation = $this->direct($this->ana, $this->beto);
        $this->signIn($this->ana)->postJson(route('chat.messages.store', $conversation), ['body' => 'uno']);
        $this->signIn($this->ana)->postJson(route('chat.messages.store', $conversation), ['body' => 'dos']);

        $this->signIn($this->beto)->getJson(route('chat.unread'))->assertJsonPath('data.unread', 2);
        $this->signIn($this->ana)->getJson(route('chat.unread'))->assertJsonPath('data.unread', 0);

        $this->signIn($this->beto)->get(route('chat.show', $conversation))->assertOk();

        $this->signIn($this->beto)->getJson(route('chat.unread'))->assertJsonPath('data.unread', 0);
    }

    public function test_mark_read_never_moves_backwards(): void
    {
        $conversation = $this->direct($this->ana, $this->beto);
        $service = app(ChatService::class);

        $service->markRead($this->beto, $conversation, 10);
        $service->markRead($this->beto, $conversation, 4);

        $this->assertSame(10, (int) \DB::table('chat_reads')->where('user_id', $this->beto->id)->value('last_read_message_id'));
    }

    public function test_cannot_send_to_a_direct_chat_whose_other_participant_became_inactive(): void
    {
        $conversation = $this->direct($this->ana, $this->beto);
        $this->beto->forceFill(['status' => 'inactive'])->save();

        $this->signIn($this->ana)->postJson(route('chat.messages.store', $conversation), ['body' => 'hola'])->assertStatus(422);
        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_attachment_upload_is_audited_without_message_content(): void
    {
        Storage::fake('local');
        $conversation = $this->direct($this->ana, $this->beto);

        $this->signIn($this->ana)->post(route('chat.messages.store', $conversation), [
            'body' => 'texto privado',
            'attachment' => UploadedFile::fake()->createWithContent('nota.txt', 'x'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $entry = Activity::query()->where('event', 'attachment_added')->firstOrFail();
        $this->assertSame('chat', $entry->log_name);
        $this->assertStringNotContainsString('texto privado', json_encode($entry->properties));
    }

    public function test_conversation_list_endpoint_returns_only_visible_conversations_with_unread(): void
    {
        $visible = $this->direct($this->ana, $this->beto);
        $this->direct($this->beto, $this->carla);
        $this->signIn($this->beto)->postJson(route('chat.messages.store', $visible), ['body' => 'nuevo']);

        $html = $this->signIn($this->ana)->getJson(route('chat.list'))->assertOk()->json('data.html');

        $this->assertStringContainsString(route('chat.show', $visible), $html);
        $this->assertStringContainsString(e($this->beto->name), $html);
        $this->assertStringNotContainsString(e($this->carla->name), $html);
    }

    public function test_image_attachment_has_an_inline_preview_only_for_participants(): void
    {
        Storage::fake('local');
        $conversation = $this->direct($this->ana, $this->beto);

        $response = $this->signIn($this->ana)->post(route('chat.messages.store', $conversation), [
            'attachment' => UploadedFile::fake()->image('foto.png', 40, 40),
        ], ['Accept' => 'application/json'])->assertCreated();

        $attachment = ChatMessage::query()->firstOrFail()->attachments()->firstOrFail();
        $this->assertStringContainsString('<img src="'.route('attachments.preview', $attachment).'"', $response->json('data.html'));

        $preview = $this->signIn($this->beto)->get(route('attachments.preview', $attachment))->assertOk();
        $preview->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('image/png', $preview->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $preview->headers->get('Content-Disposition'));

        $this->signIn($this->carla)->get(route('attachments.preview', $attachment))->assertNotFound();
    }

    public function test_non_image_attachment_has_no_preview(): void
    {
        Storage::fake('local');
        $conversation = $this->direct($this->ana, $this->beto);

        $response = $this->signIn($this->ana)->post(route('chat.messages.store', $conversation), [
            'attachment' => UploadedFile::fake()->createWithContent('nota.txt', 'x'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $attachment = ChatMessage::query()->firstOrFail()->attachments()->firstOrFail();
        $this->assertStringNotContainsString('<img', $response->json('data.html'));
        $this->signIn($this->ana)->get(route('attachments.preview', $attachment))->assertNotFound();
    }

    // ---- Busqueda de personas ----

    public function test_user_search_returns_only_active_users_with_role_and_no_email(): void
    {
        $target = User::factory()->empleado()->create(['name' => 'Zulema Buscada']);
        User::factory()->inactive()->create(['name' => 'Zulema Inactiva']);
        User::factory()->pending()->create(['name' => 'Zulema Pendiente']);

        $json = $this->signIn($this->ana)->getJson(route('chat.users', ['q' => 'Zulema']))->assertOk()->json('data');

        $this->assertCount(1, $json);
        $this->assertSame($target->id, $json[0]['id']);
        $this->assertSame(['id', 'name'], array_keys($json[0]));
    }

    public function test_user_search_excludes_self_and_escapes_like_wildcards(): void
    {
        $this->signIn($this->ana)->getJson(route('chat.users', ['q' => $this->ana->name]))->assertJsonCount(0, 'data');
        $this->signIn($this->ana)->getJson(route('chat.users', ['q' => '%']))->assertJsonCount(0, 'data');
    }

    // ---- Adjuntos ----

    public function test_attachment_is_stored_privately_and_downloadable_only_by_participants(): void
    {
        Storage::fake('local');
        $conversation = $this->direct($this->ana, $this->beto);

        $response = $this->signIn($this->ana)->post(route('chat.messages.store', $conversation), [
            'body' => 'archivo',
            'attachment' => UploadedFile::fake()->createWithContent('nota.txt', 'contenido de prueba'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $attachment = ChatMessage::query()->firstOrFail()->attachments()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertStringStartsWith('chat/'.$conversation->id.'/', $attachment->path);
        $this->assertStringContainsString(route('attachments.download', $attachment), $response->json('data.html'));

        $this->signIn($this->beto)->get(route('attachments.download', $attachment))->assertOk();
        $this->signIn($this->carla)->get(route('attachments.download', $attachment))->assertNotFound();
        $this->signIn($this->ana)->delete(route('attachments.destroy', $attachment))->assertForbidden();
    }

    public function test_disallowed_attachment_type_is_rejected(): void
    {
        Storage::fake('local');
        $conversation = $this->direct($this->ana, $this->beto);

        $this->signIn($this->ana)->post(route('chat.messages.store', $conversation), [
            'attachment' => UploadedFile::fake()->createWithContent('malo.php', '<?php echo 1;'),
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors('attachment');

        $this->assertSame(0, ChatMessage::query()->count());
    }

    // ---- Limites y rendimiento ----

    public function test_send_is_rate_limited_per_user(): void
    {
        config(['tickets.rate_limits.chat_send_per_minute' => 2]);
        RateLimiter::clear('chat-send|'.$this->ana->id);
        $conversation = $this->direct($this->ana, $this->beto);
        $url = route('chat.messages.store', $conversation);

        $this->signIn($this->ana)->postJson($url, ['body' => '1'])->assertCreated();
        $this->signIn($this->ana)->postJson($url, ['body' => '2'])->assertCreated();
        $this->signIn($this->ana)->postJson($url, ['body' => '3'])->assertStatus(429);
        $this->signIn($this->beto)->postJson($url, ['body' => 'otro usuario'])->assertCreated();
    }

    public function test_poll_query_count_does_not_grow_with_messages(): void
    {
        $conversation = $this->direct($this->ana, $this->beto);
        ChatMessage::factory()->count(3)->create(['conversation_id' => $conversation->id, 'user_id' => $this->ana->id]);

        $count = function () use ($conversation): int {
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            $this->signIn($this->beto)->getJson(route('chat.messages.index', [$conversation, 'after' => 0]))->assertOk();

            return count(\DB::getQueryLog());
        };

        $count(); // calienta cachés (permisos) que no dependen del numero de mensajes
        // Cada medicion llega con mensajes sin leer (asi las dos incluyen el mismo marcado de lectura).
        ChatMessage::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $this->ana->id]);
        $base = $count();
        ChatMessage::factory()->count(20)->create(['conversation_id' => $conversation->id, 'user_id' => $this->ana->id]);

        $this->assertSame($base, $count());
    }
}
