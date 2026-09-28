<?php

declare(strict_types=1);

namespace App\Contracts;

use Carbon\CarbonInterface;

/**
 * Mensaje IMAP ya normalizado a texto plano: la extracción de texto/HTML (preferir `text/plain`; si solo
 * hay `text/html`, `strip_tags()` + `html_entity_decode()` + colapsar espacios) sucede en el adaptador
 * concreto (MailboxClient), nunca en EmailIngestionService, que solo trabaja con `body` ya en texto plano.
 *
 * `messageId` es el header `Message-ID` real, o `null` si el correo no trae uno (EmailIngestionService
 * calcula entonces un hash determinista). `autoSubmitted`/`precedence` son los valores crudos de esas
 * cabeceras (o `null` si no vienen) usados únicamente para detectar autorespuestas/bulk.
 *
 * `native` es una referencia de implementación opaca (el mensaje real del adaptador concreto) que
 * `MailboxClient::markAsRead()` usa para marcar el mensaje como leído; EmailIngestionService nunca la
 * interpreta ni depende de su tipo.
 */
final readonly class MailboxMessage
{
    /**
     * @param  list<MailboxAttachment>  $attachments
     */
    public function __construct(
        public ?string $messageId,
        public string $fromEmail,
        public ?string $fromName,
        public string $subject,
        public CarbonInterface $date,
        public string $body,
        public ?string $autoSubmitted,
        public ?string $precedence,
        public array $attachments,
        public int $sizeBytes,
        public mixed $native = null,
    ) {}
}
