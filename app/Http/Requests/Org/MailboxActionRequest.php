<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Models\CoordinationMailbox;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Probar o eliminar el buzon de una coordinacion (sin campos).
 */
class MailboxActionRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        $mailbox = $this->route('coordination')->mailbox;

        // Sin buzon no hay nada que probar ni borrar: el servicio responde con un mensaje de negocio.
        if ($mailbox === null) {
            return $this->authorizeAbility('viewAny', CoordinationMailbox::class);
        }

        return $this->authorizeAbility($this->routeIs('mailboxes.destroy') ? 'delete' : 'test', $mailbox);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
