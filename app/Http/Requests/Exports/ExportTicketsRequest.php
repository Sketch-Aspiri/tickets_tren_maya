<?php

declare(strict_types=1);

namespace App\Http\Requests\Exports;

use App\Enums\ExportFormat;
use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Http\Requests\Tickets\IndexTicketsRequest;
use App\Models\Ticket;
use Illuminate\Validation\Rule;

/**
 * Exportacion de tickets: los mismos filtros que el listado (IndexTicketsRequest, sin paginacion) mas el formato,
 * que solo puede ser un valor del enum ExportFormat. Autoriza ANTES de validar.
 */
class ExportTicketsRequest extends IndexTicketsRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('export', Ticket::class);
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
