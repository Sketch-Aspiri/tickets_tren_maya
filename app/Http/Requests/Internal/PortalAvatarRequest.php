<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Foto de perfil unica por el puente con el portal (decision 98). La autorizacion es la firma del puente
 * (VerifyPortalBridge); la imagen la vuelve a validar y reprocesa AvatarService.
 */
class PortalAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('bridge_system') === 'portal';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $email = ['required', 'string', 'email:rfc', 'max:255'];

        return $this->isMethod('PUT')
            ? ['email' => $email, 'imagen' => ['required', 'string', 'max:2000000']]
            : ['email' => $email];
    }
}
