<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\MailboxAttachment;
use App\Contracts\MailboxClient;
use App\Contracts\MailboxMessage;
use Carbon\CarbonImmutable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attachment as ImapAttachment;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Adaptador real sobre webklex/laravel-imap (paquete `webklex/php-imap` por debajo). Traduce la API
 * concreta del paquete a los DTOs de App\Contracts para que EmailIngestionService no dependa de ella.
 *
 * `ClientManager` ya está registrado como singleton por `Webklex\IMAP\Providers\LaravelServiceProvider`
 * (lee `config('imap')`); aquí solo se usa la cuenta `default` (`config('imap.default')`).
 */
final class WebklexMailboxClient implements MailboxClient
{
    public function __construct(private readonly ClientManager $manager) {}

    /**
     * @return list<MailboxMessage>
     */
    public function fetchUnseenMessages(): array
    {
        $client = $this->manager->account();
        $client->connect();

        $folder = $client->getFolder((string) config('mail_ingestion.folder', 'INBOX'));

        if ($folder === null) {
            return [];
        }

        $messages = $folder->query()->whereUnseen()->get();

        return $messages->map(fn (ImapMessage $message): MailboxMessage => $this->toDto($message))->all();
    }

    public function markAsRead(MailboxMessage $message): void
    {
        if ($message->native instanceof ImapMessage) {
            $message->native->setFlag('Seen');
        }
    }

    private function toDto(ImapMessage $message): MailboxMessage
    {
        $from = $message->getFrom()->first();
        // Attribute::first() devuelve `false` (no null) si no hay direcciones; el operador nullsafe no
        // lo detecta, por eso se normaliza aquí antes de leer sus propiedades.
        $from = $from instanceof Address ? $from : null;

        return new MailboxMessage(
            messageId: $this->headerValue($message->getMessageId()->first()),
            fromEmail: $from?->mail ?? '',
            fromName: $from?->personal !== null && $from?->personal !== '' ? $from->personal : null,
            subject: (string) ($message->getSubject()->first() ?? ''),
            date: CarbonImmutable::instance($message->getDate()->toDate()),
            body: $this->extractPlainTextBody($message),
            autoSubmitted: $this->headerAttribute($message, 'auto_submitted'),
            precedence: $this->headerAttribute($message, 'precedence'),
            attachments: $message->getAttachments()
                ->map(fn (ImapAttachment $attachment): MailboxAttachment => new MailboxAttachment(
                    originalName: (string) ($attachment->getName() ?: ($attachment->getFilename() ?: 'archivo')),
                    content: (string) $attachment->getContent(),
                ))
                ->values()
                ->all(),
            sizeBytes: (int) $message->getSize(),
            native: $message,
        );
    }

    /**
     * Cuerpo en texto plano: preferir `text/plain`; si solo hay `text/html`, quitar etiquetas, decodificar
     * entidades y colapsar espacios. Nunca se instala un sanitizador HTML: el resultado siempre se
     * mostrará como texto escapado por Blade, jamás se renderiza como HTML.
     */
    private function extractPlainTextBody(ImapMessage $message): string
    {
        if ($message->hasTextBody()) {
            return $message->getTextBody();
        }

        if ($message->hasHTMLBody()) {
            $text = strip_tags($message->getHTMLBody());
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

            return trim((string) preg_replace('/\s+/u', ' ', $text));
        }

        return '';
    }

    private function headerAttribute(ImapMessage $message, string $name): ?string
    {
        $header = $message->getHeader();

        if ($header === null || ! $header->has($name)) {
            return null;
        }

        return $this->headerValue($header->get($name)->first());
    }

    private function headerValue(mixed $value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
