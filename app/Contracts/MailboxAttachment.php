<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Adjunto de un mensaje IMAP ya normalizado, desacoplado de webklex/laravel-imap. `originalName` viaja
 * SIN sanear (EmailIngestionService lo pasa por AttachmentInspector::sanitizeName()); `content` es el
 * binario crudo tal cual llegó en el correo.
 */
final readonly class MailboxAttachment
{
    public function __construct(
        public string $originalName,
        public string $content,
    ) {}
}
