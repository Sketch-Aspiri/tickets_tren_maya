<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Foto de perfil: solo JPG/PNG (por contenido real) y tamano/dimensiones acotados. AvatarService la vuelve a
 * validar y la reprocesa; nunca se guarda el archivo original.
 */
class UpdateAvatarRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('updateProfile', $this->user());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $max = (int) config('tickets.avatars.max_source_dimension');

        return [
            'avatar' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png',
                'mimetypes:image/jpeg,image/png',
                'max:'.(int) config('tickets.avatars.max_kilobytes'),
                'dimensions:min_width=32,min_height=32,max_width='.$max.',max_height='.$max,
            ],
        ];
    }
}
