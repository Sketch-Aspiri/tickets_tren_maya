<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\MailboxClient;
use App\Contracts\MailboxMessage;

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

    public function fetchUnseenMessages(): array
    {
        return $this->messages;
    }

    public function markAsRead(MailboxMessage $message): void
    {
        $this->markedAsRead[] = $message;
    }
}
