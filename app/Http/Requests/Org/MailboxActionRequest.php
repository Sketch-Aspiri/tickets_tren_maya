<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Probar o eliminar el buzon de una coordinacion (sin campos).
 */
class MailboxActionRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        // Administrador en cualquier coordinacion; jefe/coordinador solo en las suyas (404 en otra). Sin buzon no hay
        // nada que probar, sincronizar ni borrar: el servicio responde con un mensaje de negocio.
        return $this->authorizeAbility('manageInbox', $this->route('coordination'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
