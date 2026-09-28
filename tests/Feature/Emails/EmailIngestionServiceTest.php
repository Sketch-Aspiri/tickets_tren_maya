<?php

declare(strict_types=1);

namespace Tests\Feature\Emails;

use App\Contracts\MailboxAttachment;
use App\Contracts\MailboxMessage;
use App\Enums\IncomingEmailStatus;
use App\Models\IncomingEmail;
use App\Services\AuditLogger;
use App\Services\EmailIngestionService;
use App\Support\AttachmentInspector;
use Illuminate\Support\Facades\Storage;
use Tests\DatabaseTestCase;
use Tests\Support\FakeMailboxClient;

/**
 * EmailIngestionService::ingest(): orden barato-antes-que-caro (limite por remitente ANTES de validar
 * adjuntos), tope global por corrida (`max_per_run`) y saneado de from_email/from_name (decisiones 55-56
 * de CLAUDE.md). Sin buzon IMAP real: FakeMailboxClient entrega mensajes fijos en memoria.
 */
class EmailIngestionServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * @param  list<MailboxAttachment>  $attachments
     */
    private function message(
        string $messageId,
        string $fromEmail,
        string $subject = 'Asunto de prueba',
        array $attachments = [],
        ?string $fromName = null,
    ): MailboxMessage {
        return new MailboxMessage(
            messageId: $messageId,
            fromEmail: $fromEmail,
            fromName: $fromName,
            subject: $subject,
            date: now(),
            body: 'Cuerpo del correo de prueba.',
            autoSubmitted: null,
            precedence: null,
            attachments: $attachments,
            sizeBytes: 1024,
        );
    }

    private function service(FakeMailboxClient $mailbox): EmailIngestionService
    {
        return new EmailIngestionService($mailbox, new AttachmentInspector, app(AuditLogger::class));
    }

    // --- Orden barato-antes-que-caro (limite por remitente antes de validar adjuntos) ------------------

    public function test_a_sender_over_the_rate_limit_is_skipped_and_not_marked_as_read(): void
    {
        config(['mail_ingestion.max_per_sender_per_hour' => 1]);
        $email = new IncomingEmail([
            'message_id' => 'previous-1',
            'from_email' => 'over@example.com',
            'from_name' => null,
            'subject' => 'Anterior',
            'body' => 'x',
            'received_at' => now(),
        ]);
        $email->forceFill(['status' => IncomingEmailStatus::PendingReview])->save();

        $message = $this->message('msg-over-1', 'over@example.com', attachments: [
            new MailboxAttachment('evidencia.txt', 'contenido de prueba'),
        ]);
        $mailbox = new FakeMailboxClient([$message]);

        $result = $this->service($mailbox)->ingest();

        $this->assertSame(1, $result['skipped_invalid']);
        $this->assertSame(0, $result['created']);
        $this->assertSame([], $mailbox->markedAsRead, 'un correo limitado por remitente no se marca como leido: se reintenta en la siguiente corrida');
        $this->assertSame(1, IncomingEmail::query()->count(), 'no se crea un IncomingEmail nuevo para el mensaje limitado');
    }

    /**
     * Regresion directa del reordenamiento: en el orden ANTERIOR (adjuntos antes que limite por
     * remitente), un adjunto invalido hacia que el mensaje se marcara como leido incluso si el remitente
     * ya estaba sobre el limite, perdiendolo para siempre (nunca se reintenta). Con el limite comprobado
     * PRIMERO, el mensaje se descarta por el limite y sigue sin leerse, sin llegar nunca a inspeccionar
     * el adjunto invalido.
     */
    public function test_an_invalid_attachment_does_not_cause_a_rate_limited_message_to_be_marked_as_read(): void
    {
        config(['mail_ingestion.max_per_sender_per_hour' => 1]);
        $email = new IncomingEmail([
            'message_id' => 'previous-2',
            'from_email' => 'over2@example.com',
            'from_name' => null,
            'subject' => 'Anterior',
            'body' => 'x',
            'received_at' => now(),
        ]);
        $email->forceFill(['status' => IncomingEmailStatus::PendingReview])->save();

        $message = $this->message('msg-over-2', 'over2@example.com', attachments: [
            new MailboxAttachment('malware.exe', 'contenido binario'),
        ]);
        $mailbox = new FakeMailboxClient([$message]);

        $result = $this->service($mailbox)->ingest();

        $this->assertSame(1, $result['skipped_invalid']);
        $this->assertSame([], $mailbox->markedAsRead, 'el limite por remitente se comprueba antes de validar adjuntos: nunca llega a marcarlo leido por el adjunto invalido');
        $this->assertSame(1, IncomingEmail::query()->count());
    }

    public function test_a_sender_within_the_limit_is_ingested_normally(): void
    {
        config(['mail_ingestion.max_per_sender_per_hour' => 5]);
        $message = $this->message('msg-ok-1', 'ok@example.com');
        $mailbox = new FakeMailboxClient([$message]);

        $result = $this->service($mailbox)->ingest();

        $this->assertSame(1, $result['created']);
        $this->assertCount(1, $mailbox->markedAsRead);
        $this->assertSame(1, IncomingEmail::query()->count());
    }

    // --- Tope global por corrida (max_per_run) ----------------------------------------------------------

    public function test_max_per_run_caps_processing_and_leaves_the_rest_unread_for_the_next_run(): void
    {
        config(['mail_ingestion.max_per_run' => 2, 'mail_ingestion.max_per_sender_per_hour' => 100]);

        $messages = [
            $this->message('msg-cap-1', 'a@example.com'),
            $this->message('msg-cap-2', 'b@example.com'),
            $this->message('msg-cap-3', 'c@example.com'),
        ];
        $mailbox = new FakeMailboxClient($messages);

        $result = $this->service($mailbox)->ingest();

        $this->assertSame(3, $result['fetched']);
        $this->assertSame(2, $result['created']);
        $this->assertTrue($result['capped']);
        $this->assertCount(2, $mailbox->markedAsRead, 'solo los mensajes dentro del tope se marcan como leidos');
        $this->assertSame(0, $result['failed'], 'los mensajes por encima del tope no cuentan como fallidos');
        $this->assertSame(2, IncomingEmail::query()->count());
        $this->assertDatabaseMissing('incoming_emails', ['message_id' => 'msg-cap-3']);
    }

    public function test_max_per_run_is_not_capped_when_fetched_messages_are_within_the_limit(): void
    {
        config(['mail_ingestion.max_per_run' => 200, 'mail_ingestion.max_per_sender_per_hour' => 100]);

        $mailbox = new FakeMailboxClient([$this->message('msg-nocap-1', 'z@example.com')]);

        $result = $this->service($mailbox)->ingest();

        $this->assertFalse($result['capped']);
        $this->assertSame(1, $result['created']);
    }

    // --- Saneado de from_email/from_name ----------------------------------------------------------------

    public function test_from_email_and_from_name_are_stripped_of_control_characters_and_truncated(): void
    {
        config(['mail_ingestion.max_per_sender_per_hour' => 100]);

        $dirtyEmail = "control\x01@example.com";
        $dirtyName = "Nombre\x07 Sucio";
        $mailbox = new FakeMailboxClient([
            $this->message('msg-sanitize-1', $dirtyEmail, fromName: $dirtyName),
        ]);

        $result = $this->service($mailbox)->ingest();

        $this->assertSame(1, $result['created']);
        $stored = IncomingEmail::query()->where('message_id', 'msg-sanitize-1')->firstOrFail();
        $this->assertSame('control@example.com', $stored->from_email);
        $this->assertSame('Nombre Sucio', $stored->from_name);
    }

    public function test_from_email_and_from_name_are_truncated_to_255_characters(): void
    {
        config(['mail_ingestion.max_per_sender_per_hour' => 100]);

        $longLocalPart = str_repeat('a', 300);
        $longName = str_repeat('b', 300);
        $mailbox = new FakeMailboxClient([
            $this->message('msg-sanitize-2', "{$longLocalPart}@example.com", fromName: $longName),
        ]);

        $this->service($mailbox)->ingest();

        $stored = IncomingEmail::query()->where('message_id', 'msg-sanitize-2')->firstOrFail();
        $this->assertSame(255, mb_strlen($stored->from_email));
        $this->assertSame(255, mb_strlen((string) $stored->from_name));
    }

    public function test_a_null_from_name_is_persisted_as_null(): void
    {
        config(['mail_ingestion.max_per_sender_per_hour' => 100]);

        $mailbox = new FakeMailboxClient([$this->message('msg-sanitize-3', 'sinnombre@example.com', fromName: null)]);

        $this->service($mailbox)->ingest();

        $stored = IncomingEmail::query()->where('message_id', 'msg-sanitize-3')->firstOrFail();
        $this->assertNull($stored->from_name);
    }
}
