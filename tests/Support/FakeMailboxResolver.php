<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\MailboxClient;
use App\Contracts\MailboxMessage;
use App\Contracts\MailboxResolver;
use App\Models\CoordinationMailbox;
use RuntimeException;

/**
 * Doble de MailboxResolver: entrega un FakeMailboxClient por coordinacion (o falla) y registra las pruebas de
 * conexion, sin tocar ningun servidor IMAP.
 */
final class FakeMailboxResolver implements MailboxResolver
{
    /** @var array<int, FakeMailboxClient> */
    private array $clients = [];

    /** @var array<int, string> coordination_id => mensaje de error */
    private array $failures = [];

    /** @var list<int> */
    public array $testedCoordinationIds = [];

    /**
     * @param  list<MailboxMessage>  $messages
     */
    public function serve(int $coordinationId, array $messages): FakeMailboxClient
    {
        return $this->clients[$coordinationId] = new FakeMailboxClient($messages);
    }

    public function failWith(int $coordinationId, string $message): void
    {
        $this->failures[$coordinationId] = $message;
    }

    public function clientFor(CoordinationMailbox $mailbox): MailboxClient
    {
        if (isset($this->failures[$mailbox->coordination_id])) {
            throw new RuntimeException($this->failures[$mailbox->coordination_id]);
        }

        return $this->clients[$mailbox->coordination_id] ??= new FakeMailboxClient([]);
    }

    public function test(CoordinationMailbox $mailbox): void
    {
        $this->testedCoordinationIds[] = $mailbox->coordination_id;

        if (isset($this->failures[$mailbox->coordination_id])) {
            throw new RuntimeException($this->failures[$mailbox->coordination_id]);
        }
    }
}
