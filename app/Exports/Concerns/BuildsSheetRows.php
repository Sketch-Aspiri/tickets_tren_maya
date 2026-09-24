<?php

declare(strict_types=1);

namespace App\Exports\Concerns;

use App\Enums\AssignmentRole;
use Illuminate\Database\Eloquent\Model;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * Piezas comunes de las hojas exportadas: toda celda es TEXTO (StringValueBinder: nada se interpreta como
 * formula), fechas en hora de negocio y nombres de responsable/colaboradores (requiere `assignments.user` cargado).
 */
trait BuildsSheetRows
{
    public function bindValue(Cell $cell, mixed $value): bool
    {
        return (new StringValueBinder)->bindValue($cell, $value);
    }

    private function local(mixed $moment): ?string
    {
        return $moment?->copy()->timezone((string) config('app.display_timezone'))->format('Y-m-d H:i');
    }

    private function responsibleName(Model $row): ?string
    {
        return $row->assignments->firstWhere('role', AssignmentRole::Responsable)?->user?->name;
    }

    private function collaboratorNames(Model $row): string
    {
        return $row->assignments
            ->where('role', AssignmentRole::Colaborador)
            ->map(fn ($assignment): string => (string) $assignment->user?->name)
            ->implode(', ');
    }
}
