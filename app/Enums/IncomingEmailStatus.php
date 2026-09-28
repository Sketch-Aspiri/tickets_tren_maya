<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de triage de un correo entrante (bandeja "Correos entrantes"). Un correo nunca se borra:
 * `discarded` es un valor de este enum, no un borrado de la fila.
 */
enum IncomingEmailStatus: string
{
    case PendingReview = 'pending_review';
    case Converted = 'converted';
    case Discarded = 'discarded';

    public function label(): string
    {
        return __('enums.incoming_email_status.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
