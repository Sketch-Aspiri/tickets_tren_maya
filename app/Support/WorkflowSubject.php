<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Activity;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que comparten tickets y actividades en los servicios genéricos (transiciones, asignación, comentarios y
 * adjuntos): el nombre de bitácora y el grupo de traducciones (`tickets.*` o `activities.*`) se derivan
 * del tipo, en un solo lugar, para no ramificar por tipo en cada servicio.
 */
final class WorkflowSubject
{
    /**
     * Nombre de bitácora y grupo de traducciones: `tickets` o `activities`.
     */
    public static function group(Model $subject): string
    {
        return $subject instanceof Activity ? 'activities' : 'tickets';
    }

    /**
     * Clave de traducción de un mensaje de error (`tickets.errors.closed` / `activities.errors.closed`).
     */
    public static function error(Model $subject, string $key): string
    {
        return self::group($subject).'.errors.'.$key;
    }

    /**
     * Directorio (dentro del disco privado) de los adjuntos de este tipo de registro.
     */
    public static function attachmentDirectory(Ticket|Activity $subject): string
    {
        $key = $subject instanceof Activity ? 'tickets.attachments.activity_directory' : 'tickets.attachments.directory';

        return trim((string) config($key), '/').'/'.(int) $subject->getKey();
    }
}
