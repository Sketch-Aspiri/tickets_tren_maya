<?php

declare(strict_types=1);

namespace Tests\Feature\Emails;

use App\Contracts\MailboxMessage;
use App\Contracts\MailboxResolver;
use App\Models\Coordination;
use App\Models\CoordinationMailbox;
use App\Models\IncomingEmail;
use App\Models\User;
use App\Notifications\NewIncomingEmailToReview;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\DatabaseTestCase;
use Tests\Support\FakeMailboxResolver;

/**
 * Un buzon por coordinacion: cada uno entrega a su propia bandeja, se procesan aislados y los avisos van a los
 * jefes de esa coordinacion.
 */
class MailboxPollerTest extends DatabaseTestCase
{
    private Coordination $coordA;

    private Coordination $coordB;

    private FakeMailboxResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->coordA = Coordination::factory()->create();
        $this->coordB = Coordination::factory()->create();
        $this->resolver = new FakeMailboxResolver;
        $this->app->instance(MailboxResolver::class, $this->resolver);
    }

    private function mailbox(Coordination $coordination, bool $active = true): CoordinationMailbox
    {
        return CoordinationMailbox::query()->create([
            'coordination_id' => $coordination->id,
            'host' => 'imap.ejemplo.mx',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'tickets-'.$coordination->id.'@ejemplo.mx',
            'password' => 'secreto',
            'folder' => 'INBOX',
            'active' => $active,
        ]);
    }

    private function message(string $id, string $from = 'externo@example.com'): MailboxMessage
    {
        return new MailboxMessage(
            messageId: $id,
            fromEmail: $from,
            fromName: null,
            subject: 'Asunto '.$id,
            date: now(),
            body: 'Cuerpo',
            autoSubmitted: null,
            precedence: null,
            attachments: [],
            sizeBytes: 1024,
        );
    }

    public function test_each_mailbox_delivers_to_its_own_coordination(): void
    {
        $this->mailbox($this->coordA);
        $this->mailbox($this->coordB);
        $this->resolver->serve($this->coordA->id, [$this->message('a-1'), $this->message('a-2')]);
        $this->resolver->serve($this->coordB->id, [$this->message('b-1')]);

        $this->artisan('emails:ingest')->assertSuccessful();

        $this->assertSame(2, IncomingEmail::query()->where('coordination_id', $this->coordA->id)->count());
        $this->assertSame(1, IncomingEmail::query()->where('coordination_id', $this->coordB->id)->count());
        $this->assertNotNull(CoordinationMailbox::query()->where('coordination_id', $this->coordA->id)->value('last_polled_at'));
        $this->assertNull(CoordinationMailbox::query()->where('coordination_id', $this->coordA->id)->value('last_error'));
    }

    public function test_the_same_message_id_is_allowed_in_two_coordinations_but_deduplicated_within_one(): void
    {
        $this->mailbox($this->coordA);
        $this->mailbox($this->coordB);
        $this->resolver->serve($this->coordA->id, [$this->message('same-id')]);
        $this->resolver->serve($this->coordB->id, [$this->message('same-id')]);

        $this->artisan('emails:ingest')->assertSuccessful();
        $this->artisan('emails:ingest')->assertSuccessful();

        $this->assertSame(1, IncomingEmail::query()->where('coordination_id', $this->coordA->id)->count());
        $this->assertSame(1, IncomingEmail::query()->where('coordination_id', $this->coordB->id)->count());
    }

    public function test_a_failing_mailbox_does_not_stop_the_others_and_is_recorded(): void
    {
        $this->mailbox($this->coordA);
        $this->mailbox($this->coordB);
        $this->resolver->failWith($this->coordA->id, 'Connection refused');
        $this->resolver->serve($this->coordB->id, [$this->message('b-1')]);

        $this->artisan('emails:ingest')->assertFailed();

        $this->assertSame(0, IncomingEmail::query()->where('coordination_id', $this->coordA->id)->count());
        $this->assertSame(1, IncomingEmail::query()->where('coordination_id', $this->coordB->id)->count());
        $this->assertStringContainsString('Connection refused', (string) CoordinationMailbox::query()->where('coordination_id', $this->coordA->id)->value('last_error'));
        $this->assertNull(CoordinationMailbox::query()->where('coordination_id', $this->coordB->id)->value('last_error'));
        $this->assertTrue(Activity::query()->where('log_name', 'mailboxes')->where('event', 'poll_failed')->exists());
    }

    public function test_the_error_is_cleared_after_a_successful_poll(): void
    {
        $mailbox = $this->mailbox($this->coordA);
        $mailbox->forceFill(['last_error' => 'Viejo error'])->save();
        $this->resolver->serve($this->coordA->id, []);

        $this->artisan('emails:ingest')->assertSuccessful();

        $this->assertNull($mailbox->fresh()->last_error);
    }

    public function test_inactive_mailboxes_are_skipped(): void
    {
        $this->mailbox($this->coordA, active: false);
        $this->resolver->serve($this->coordA->id, [$this->message('a-1')]);

        $this->artisan('emails:ingest')->assertSuccessful();

        $this->assertSame(0, IncomingEmail::query()->count());
    }

    public function test_the_sender_limit_applies_per_mailbox(): void
    {
        config(['mail_ingestion.max_per_sender_per_hour' => 1]);
        $this->mailbox($this->coordA);
        $this->mailbox($this->coordB);
        $this->resolver->serve($this->coordA->id, [$this->message('a-1', 'spam@example.com'), $this->message('a-2', 'spam@example.com')]);
        $this->resolver->serve($this->coordB->id, [$this->message('b-1', 'spam@example.com')]);

        $this->artisan('emails:ingest');

        $this->assertSame(1, IncomingEmail::query()->where('coordination_id', $this->coordA->id)->count());
        $this->assertSame(1, IncomingEmail::query()->where('coordination_id', $this->coordB->id)->count());
    }

    public function test_new_email_notices_go_to_the_bosses_of_that_coordination_only(): void
    {
        Notification::fake();
        $bossA = User::factory()->jefeOf($this->coordA)->create();
        $bossB = User::factory()->jefeOf($this->coordB)->create();
        $admin = User::factory()->administrador()->create();
        $this->mailbox($this->coordA);
        $this->mailbox($this->coordB);
        $this->resolver->serve($this->coordA->id, [$this->message('a-1')]);
        $this->resolver->serve($this->coordB->id, []);

        $this->artisan('emails:ingest')->assertSuccessful();

        Notification::assertSentTo($bossA, NewIncomingEmailToReview::class);
        Notification::assertNotSentTo($bossB, NewIncomingEmailToReview::class);
        Notification::assertNotSentTo($admin, NewIncomingEmailToReview::class);
    }

    public function test_administrators_are_notified_when_the_coordination_has_no_active_boss(): void
    {
        Notification::fake();
        $admin = User::factory()->administrador()->create();
        $this->mailbox($this->coordA);
        $this->resolver->serve($this->coordA->id, [$this->message('a-1')]);

        $this->artisan('emails:ingest')->assertSuccessful();

        Notification::assertSentTo($admin, NewIncomingEmailToReview::class);
    }

    public function test_the_failure_reason_never_contains_the_password(): void
    {
        $this->mailbox($this->coordA);
        $this->resolver->failWith($this->coordA->id, 'LOGIN failed for tickets-1@ejemplo.mx');

        $this->artisan('emails:ingest')->assertFailed();

        $this->assertStringNotContainsString('secreto', Activity::query()->get()->toJson());
        $this->assertStringNotContainsString('secreto', (string) CoordinationMailbox::query()->value('last_error'));
    }
}
