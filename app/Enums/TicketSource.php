<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Origen del ticket. `email` lo usara la ingesta de correos (Sprint 4).
 */
enum TicketSource: string
{
    case Web = 'web';
    case Email = 'email';

    public function label(): string
    {
        return __('enums.ticket_source.'.$this->value);
    }
}
