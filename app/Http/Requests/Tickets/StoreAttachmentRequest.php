<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Rules\AllowedAttachment;
use Illuminate\Foundation\Http\FormRequest;

class StoreAttachmentRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('attach', $this->route('ticket'));
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
