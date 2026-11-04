<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\CoordinationMailbox;

/**
 * Construye el cliente de un buzon concreto y prueba su conexion. Implementacion real:
 * App\Infrastructure\WebklexMailboxResolver; las pruebas la sustituyen por un doble.
 */
interface MailboxResolver
{
    public function clientFor(CoordinationMailbox $mailbox): MailboxClient;

    /**
     * Abre y cierra la conexion. Lanza una excepcion si no se puede conectar o autenticar.
     */
    public function test(CoordinationMailbox $mailbox): void;
}
