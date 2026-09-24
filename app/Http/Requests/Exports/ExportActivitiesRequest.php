<?php

declare(strict_types=1);

namespace App\Http\Requests\Exports;

use App\Enums\ExportFormat;
use App\Http\Requests\Activities\IndexActivitiesRequest;
use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Models\Activity;
use Illuminate\Validation\Rule;

/**
 * Exportacion de actividades: los mismos filtros que el listado (IndexActivitiesRequest, sin paginacion) mas el
 * formato, que solo puede ser un valor del enum ExportFormat. Autoriza ANTES de validar.
 */
class ExportActivitiesRequest extends IndexActivitiesRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('export', Activity::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['page']);

        return [...$rules, 'format' => ['nullable', 'string', Rule::enum(ExportFormat::class)]];
    }

    public function exportFormat(): ExportFormat
    {
        return ExportFormat::tryFrom((string) $this->validated('format')) ?? ExportFormat::Xlsx;
    }
}
