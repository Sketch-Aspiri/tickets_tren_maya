<?php

declare(strict_types=1);

namespace App\Services\SheetInbox;

use RuntimeException;

/**
 * Fallo de la bandeja desde Excel. El mensaje es seguro de mostrar y guardar: nunca lleva tokens, secretos ni el
 * cuerpo de las respuestas de Microsoft.
 */
final class SheetInboxException extends RuntimeException
{
    public static function because(string $translationKey, array $replace = []): self
    {
        return new self(__($translationKey, $replace));
    }
}
