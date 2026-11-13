<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una propuesta de actividad hecha por la IA sobre un correo entrante (decision 94).
 */
enum EmailProposalStatus: string
{
    /** Espera a que una persona la confirme, corrija o rechace. */
    case Pending = 'pending';

    /** Una persona convirtio el correo en actividad (con `unchanged` = sin tocar la propuesta). */
    case Accepted = 'accepted';

    /** La IA creo la actividad sola (modo automatico confiable). */
    case AutoCreated = 'auto_created';

    /** El correo se descarto. */
    case Rejected = 'rejected';

    /** Una propuesta posterior sobre el mismo correo la reemplazo. */
    case Superseded = 'superseded';

    public function label(): string
    {
        return __('emails.proposal.status.'.$this->value);
    }
}
