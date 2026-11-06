<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Carga manual del libro de Excel de la bandeja de una coordinación. Misma autorización que el resto de la bandeja
 * (`manageInbox`, antes de validar: otra coordinación responde 404). Solo .xlsx; el contenido se vuelve a comprobar
 * al leerlo (un archivo que no es un libro válido se rechaza sin importar nada).
 */
class UploadSheetRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('manageInbox', $this->route('coordination'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'extensions:xlsx',
                'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream',
                'max:'.max(1, (int) config('tickets.sheet_inbox.max_file_kb')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => __('sheet_sources.errors.no_file'),
            'file.extensions' => __('sheet_sources.errors.not_xlsx'),
            'file.mimetypes' => __('sheet_sources.errors.not_xlsx'),
            'file.max' => __('sheet_sources.errors.too_big', ['max' => (int) config('tickets.sheet_inbox.max_file_kb')]),
        ];
    }
}
