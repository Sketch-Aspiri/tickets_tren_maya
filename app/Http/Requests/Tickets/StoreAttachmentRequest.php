<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Http\Requests\Concerns\ResolvesWorkItem;
use App\Rules\AllowedAttachment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adjuntar. Sirve a tickets y a actividades (ruta `{ticket}` o `{activity}`).
 */
class StoreAttachmentRequest extends FormRequest
{
    use AuthorizesWithGate, ResolvesWorkItem;

    public function authorize(): bool
    {
        return $this->authorizeAbility('attach', $this->workItem());
    }

    /**
     * Tipo por extensión + MIME real (AllowedAttachment) y tamaño máximo configurable.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.(int) config('tickets.attachments.max_kilobytes'),
                new AllowedAttachment,
            ],
        ];
    }
}
