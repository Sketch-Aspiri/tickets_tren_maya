<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\MailboxClient;
use App\Contracts\MailboxMessage;
use Carbon\CarbonInterface;

/**
 * Doble de prueba de MailboxClient (sin buzon IMAP real): entrega una lista fija de mensajes y solo
 * registra cuales se marcaron como leidos, para que las pruebas de EmailIngestionService puedan afirmar
 * exactamente eso (p. ej. que un mensaje limitado por remitente o por el tope global NO se marca).
 */
final class FakeMailboxClient implements MailboxClient
{
    /** @var list<MailboxMessage> */
    public array $markedAsRead = [];

    /**
     * @param  list<MailboxMessage>  $messages
     */
    public function __construct(private array $messages) {}

    /** Argumentos de la última llamada a fetchMessagesSince(), para afirmar la ventana y el tope pedidos. */
    public ?CarbonInterface $lastSince = null;

    public ?int $lastLimit = null;

    /**
     * Devuelve la lista fija tal cual (sin filtrar por $isKnown ni recortar a $limit) para que las pruebas
     * del servicio sigan cubriendo su propio dedup y su tope por corrida.
     */
    public function fetchMessagesSince(CarbonInterface $since, int $limit, callable $isKnown): array
    {
        $this->lastSince = $since;
        $this->lastLimit = $limit;

        return $this->messages;
    }

    public function markAsRead(MailboxMessage $message): void
    {
        $this->markedAsRead[] = $message;
    }
}
