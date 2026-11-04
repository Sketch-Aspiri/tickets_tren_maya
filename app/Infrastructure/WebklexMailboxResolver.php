<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\HostResolver;
use App\Contracts\MailboxClient;
use App\Contracts\MailboxResolver;
use App\Models\CoordinationMailbox;
use App\Rules\PublicMailHost;
use RuntimeException;
use Webklex\PHPIMAP\ClientManager;

/**
 * Cuenta IMAP dinamica por buzon de coordinacion (`ClientManager::make`), con la contrasena descifrada solo en
 * memoria y solo para esta conexion.
 */
final class WebklexMailboxResolver implements MailboxResolver
{
    public function __construct(private readonly ClientManager $manager, private readonly HostResolver $hosts) {}

    public function clientFor(CoordinationMailbox $mailbox): MailboxClient
    {
        return new WebklexMailboxClient($this->manager, $this->accountFor($mailbox), $mailbox->folder);
    }

    public function test(CoordinationMailbox $mailbox): void
    {
        $client = $this->manager->make($this->accountFor($mailbox));
        $client->connect();
        $client->disconnect();
    }

    /**
     * @return array<string, mixed>
     */
    private function accountFor(CoordinationMailbox $mailbox): array
    {
        $this->assertHostIsStillPublic($mailbox->host);

        return [
            'host' => $mailbox->host,
            'port' => $mailbox->port,
            'protocol' => 'imap',
            'encryption' => $mailbox->encryption === 'none' ? false : $mailbox->encryption,
            'validate_cert' => true,
            'username' => $mailbox->username,
            'password' => $mailbox->password,
            'authentication' => null,
            'proxy' => ['socket' => null, 'request_fulluri' => false, 'username' => null, 'password' => null],
            'timeout' => 30,
            'extensions' => [],
        ];
    }

    /**
     * El DNS pudo cambiar desde que se guardo el buzon (rebinding): se resuelve otra vez y se rechaza si alguna
     * direccion ya no es publica.
     */
    private function assertHostIsStillPublic(string $host): void
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->hosts->resolve($host);

        if ($addresses === []) {
            throw new RuntimeException('El servidor del buzon no resuelve.');
        }

        foreach ($addresses as $address) {
            if (! PublicMailHost::isPublic($address)) {
                throw new RuntimeException('El servidor del buzon resuelve a una direccion no publica.');
            }
        }
    }
}
