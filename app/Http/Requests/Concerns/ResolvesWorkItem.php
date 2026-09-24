<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Activity;
use App\Models\Ticket;

/**
 * Los Form Requests de asignar, transicionar, comentar y adjuntar sirven igual a tickets (`{ticket}`) y a
 * actividades (`{activity}`): la Policy que se consulta es la del modelo que trae la ruta.
 */
trait ResolvesWorkItem
{
    protected function workItem(): Ticket|Activity
    {
        $item = $this->route('ticket') ?? $this->route('activity');

        abort_unless($item instanceof Ticket || $item instanceof Activity, 404);

        return $item;
    }
}
