<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Superficie mínima que EmailIngestionService necesita de un buzón IMAP, desacoplada de la API concreta
 * de webklex/laravel-imap para poder probarse sin un buzón real. Implementación real:
 * App\Infrastructure\WebklexMailboxClient. Implementación de prueba (slice de pruebas, aparte): un
 * FakeMailboxClient bajo tests/.
 */
interface MailboxClient
{
    /**
     * Mensajes no leídos del buzón configurado (config('mail_ingestion.folder')), ya normalizados.
     *
     * @return list<MailboxMessage>
     */
    public function fetchUnseenMessages(): array;

    /**
     * Marca el mensaje original como leído (IMAP flag "Seen").
     */
    public function markAsRead(MailboxMessage $message): void;
}
