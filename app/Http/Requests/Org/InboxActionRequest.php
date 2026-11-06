<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Acciones sin campos sobre la bandeja de una coordinación (conectar/desconectar la cuenta, sincronizar, borrar la
 * hoja). Administrador en cualquiera; jefe y coordinador solo en las suyas (404 en otra).
 */
class InboxActionRequest extends FormRequest
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
        return [];
    }
}
