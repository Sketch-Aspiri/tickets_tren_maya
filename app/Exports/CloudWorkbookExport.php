<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Libro que se sube a la nube: Tickets, Actividades (las mismas hojas y protecciones de la exportacion manual)
 * e Info.
 */
final class CloudWorkbookExport implements Export, WithMultipleSheets
{
    public function __construct(
        private readonly TicketsExport $tickets,
        private readonly ActivitiesExport $activities,
        private readonly CloudInfoSheet $info,
    ) {}

    /**
     * @return list<object>
     */
    public function sheets(): array
    {
        return [$this->tickets, $this->activities, $this->info];
    }
}
