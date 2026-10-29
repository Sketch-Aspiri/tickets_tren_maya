<?php

declare(strict_types=1);

namespace App\Contracts;

use Carbon\CarbonInterface;

/**
 * Superficie mínima que EmailIngestionService necesita de un buzón IMAP, desacoplada de la API concreta
 * de webklex/laravel-imap para poder probarse sin un buzón real. Implementación real:
 * App\Infrastructure\WebklexMailboxClient. Implementación de prueba (slice de pruebas, aparte): un
 * FakeMailboxClient bajo tests/.
 */
interface MailboxClient
{
    /**
     * Mensajes del buzón configurado (config('mail_ingestion.folder')) recibidos desde `$since` (criterio
     * IMAP `SINCE`, con granularidad de día), leídos o no, del más antiguo al más nuevo y ya normalizados.
     * Se busca por fecha y no por "no leído" para que un correo abierto a mano en el buzón no se pierda.
     *
     * Los mensajes cuyo `Message-ID` reconozca `$isKnown` (ya ingeridos) se omiten SIN descargar su cuerpo,
     * y se devuelven como máximo `$limit` mensajes (`0` = sin tope).
     *
     * @param  callable(string): bool  $isKnown
     * @return list<MailboxMessage>
     */
    public function fetchMessagesSince(CarbonInterface $since, int $limit, callable $isKnown): array;

    /**
     * Marca el mensaje original como leído (IMAP flag "Seen").
     */
    public function markAsRead(MailboxMessage $message): void;
}
