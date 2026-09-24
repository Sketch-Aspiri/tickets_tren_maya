<?php

declare(strict_types=1);

namespace App\Exports;

use App\Exports\Concerns\BuildsSheetRows;
use App\Models\Ticket;
use App\Support\SpreadsheetSanitizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Listado de tickets a Excel basico. Recibe filas YA acotadas por alcance y filtros (TicketListingService), con
 * `category`, `team` y `assignments.user` cargados. Todas las celdas son TEXTO (BuildsSheetRows) y ademas cada
 * texto de usuario pasa por SpreadsheetSanitizer.
 *
 * @implements FromCollection<int, Ticket>
 */
final class TicketsExport implements FromCollection, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithMapping, WithTitle
{
    use BuildsSheetRows, Exportable;

    /**
     * @param  Collection<int, Ticket>  $tickets
     */
    public function __construct(private readonly Collection $tickets) {}

    /**
     * @return Enumerable<int, Ticket>
     */
    public function collection(): Enumerable
    {
        return $this->tickets;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return array_map(
            fn (string $key): string => __('tickets.export.columns.'.$key),
            ['folio', 'title', 'status', 'priority', 'category', 'team', 'responsible', 'collaborators', 'due_date', 'overdue', 'created', 'completed', 'source'],
        );
    }

    /**
     * @param  Ticket  $row
     * @return list<string>
     */
    public function map($row): array
    {
        return array_map(SpreadsheetSanitizer::cell(...), [
            $row->folio,
            $row->title,
            $row->status->label(),
            $row->priority->label(),
            $row->category?->name,
            $row->team?->name,
            $this->responsibleName($row),
            $this->collaboratorNames($row),
            $row->due_date?->toDateString(),
            $row->isOverdue() ? __('common.yes') : __('common.no'),
            $this->local($row->created_at),
            $this->local($row->completed_at),
            $row->source->label(),
        ]);
    }

    public function title(): string
    {
        return __('tickets.export.sheet');
    }
}
