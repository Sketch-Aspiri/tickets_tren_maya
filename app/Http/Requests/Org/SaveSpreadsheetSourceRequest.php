<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Rules\OneDriveShareUrl;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Configuración de la hoja de Excel de una coordinación: enlace compartido, hoja y qué columna es cada dato.
 * Las columnas de descripción se escriben una por línea en el formulario.
 */
class SaveSpreadsheetSourceRequest extends FormRequest
{
    use AuthorizesWithGate;

    private const COLUMN = ['nullable', 'string', 'max:100'];

    public function authorize(): bool
    {
        return $this->authorizeAbility('manageInbox', $this->route('coordination'));
    }

    protected function prepareForValidation(): void
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $this->input('body_columns_text', '')) ?: [];

        $this->merge([
            'body_columns' => array_values(array_unique(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== ''))),
            'active' => $this->boolean('active'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'share_url' => ['required', 'string', 'max:2000', new OneDriveShareUrl],
            'worksheet' => self::COLUMN,
            'header_row' => ['required', 'integer', 'min:1', 'max:50'],
            'subject_column' => ['required', 'string', 'max:100'],
            'body_columns' => ['array', 'max:15'],
            'body_columns.*' => ['string', 'max:100'],
            'requester_name_column' => self::COLUMN,
            'requester_email_column' => self::COLUMN,
            'key_column' => self::COLUMN,
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->safe()->only([
            'name', 'share_url', 'worksheet', 'header_row', 'subject_column', 'body_columns',
            'requester_name_column', 'requester_email_column', 'key_column', 'active',
        ]);
    }
}
