<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\MailboxAttachment;
use App\Contracts\MailboxClient;
use App\Contracts\MailboxMessage;
use Carbon\CarbonImmutable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attachment as ImapAttachment;
use Throwable;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;
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

        // Solo los UID (barato). El cuerpo y los adjuntos se descargan de uno en uno, después de comprobar el
        // tamaño: bajar todo con ->get() agotaba la memoria con un solo correo enorme sin leer, y como el
        // proceso moría antes de marcarlo, bloqueaba la ingesta de todos los correos nuevos.
        $uids = $folder->query()->whereUnseen()->search()->values()->all();
        $maxPerRun = (int) config('mail_ingestion.max_per_run');
        $maxBytes = (int) config('mail_ingestion.max_email_kb') * 1024;
        $connection = $client->getConnection();

        if ($maxPerRun > 0) {
            $uids = array_slice($uids, 0, $maxPerRun);
        }

        $messages = [];

        foreach ($uids as $uid) {
            try {
                $size = (int) ($connection->sizes([(int) $uid], IMAP::ST_UID)->validatedData()[$uid] ?? 0);

                if ($maxBytes > 0 && $size > $maxBytes) {
                    // Demasiado grande para ingerirlo: se marca como leído sin descargarlo.
                    $connection->store(['\\Seen'], (int) $uid, (int) $uid, '+', true, IMAP::ST_UID);

                    continue;
                }

                $messages[] = $this->toDto($folder->query()->getMessageByUid($uid));
            } catch (Throwable $exception) {
                // Un correo problemático no debe impedir leer el resto del buzón.
                report($exception);
            }
        }

        return $messages;
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
            fromName: $from?->personal !== null && $from?->personal !== '' ? $this->decodeHeaderText($from->personal) : null,
            subject: $this->decodeHeaderText((string) ($message->getSubject()->first() ?? '')),
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
            return $this->htmlToText($message->getHTMLBody());
        }

        return '';
    }

    /**
     * strip_tags() conserva el CONTENIDO de <style>, <script> y <head> (CSS, @font-face...), que en
     * correos de marketing es la mayor parte del texto; se eliminan esos bloques y los comentarios
     * condicionales antes, y los cortes de bloque se convierten en saltos de línea para conservar párrafos.
     */
    private function htmlToText(string $html): string
    {
        $html = (string) preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1\s*>#isu', ' ', $html);
        $html = (string) preg_replace('/<!--.*?-->/su', ' ', $html);
        $html = (string) preg_replace('#<br\s*/?>|</(p|div|tr|li|h[1-6]|table)\s*>#iu', "\n", $html);

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/ ?\n ?/u', "\n", $text);

        return trim((string) preg_replace('/\n{3,}/u', "\n\n", $text));
    }

    /**
     * Decodifica palabras codificadas RFC 2047 (`=?UTF-8?B?...?=`) que el paquete deja sin decodificar
     * cuando vienen varias separadas por espacio; si ya viene en claro, no cambia nada.
     */
    private function decodeHeaderText(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = mb_decode_mimeheader($value);

        return $decoded !== '' ? $decoded : $value;
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
