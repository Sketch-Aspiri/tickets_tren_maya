<?php

declare(strict_types=1);

namespace App\Exports;

use App\Exports\Concerns\BuildsSheetRows;
use App\Models\Activity;
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
 * Listado de actividades a Excel basico. Recibe filas YA acotadas por alcance y filtros (ActivityListingService),
 * con `category`, `team`, `parent`, `assignments.user` y los conteos de avance (`withProgress`) cargados, de modo
 * que no hay consultas por fila. Todas las celdas son TEXTO (BuildsSheetRows) y cada texto de usuario pasa por
 * SpreadsheetSanitizer.
 *
 * @implements FromCollection<int, Activity>
 */
final class ActivitiesExport implements FromCollection, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithMapping, WithTitle
{
    use BuildsSheetRows, Exportable;

    /**
     * @param  Collection<int, Activity>  $activities
     */
    public function __construct(private readonly Collection $activities) {}

    /**
     * @return Enumerable<int, Activity>
     */
    public function collection(): Enumerable
    {
        return $this->activities;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return array_map(
            fn (string $key): string => __('activities.export.columns.'.$key),
            ['folio', 'title', 'status', 'priority', 'category', 'team', 'responsible', 'collaborators', 'start_date', 'due_date', 'progress', 'overdue', 'recurrence', 'created'],
        );
    }

    /**
     * @param  Activity  $row
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
            $row->start_date?->toDateString(),
            $row->due_date?->toDateString(),
            $row->progressPercent().' %',
            $row->isOverdue() ? __('common.yes') : __('common.no'),
            $this->recurrence($row),
            $this->local($row->created_at),
        ]);
    }

    public function title(): string
    {
        return __('activities.export.sheet');
    }

    private function recurrence(Activity $row): string
    {
        return match (true) {
            $row->isTemplate() => __('activities.export.template'),
            $row->isInstance() => __('activities.export.instance', ['folio' => (string) $row->parent?->folio]),
            default => __('activities.export.single'),
        };
    }
}
