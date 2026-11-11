<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Support\EcosystemUrl;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * `POST /interno/avisos` del puente con el Dashboard (decision 91). Ya paso por `VerifyBridge` (firma, fecha,
 * nonce e IP); aqui se autoriza que lo mande el Dashboard (unico sistema que avisa) y se validan los limites:
 * hasta 50 correos, titulo <= 60, cuerpo <= 120 (se ven en la pantalla bloqueada) y una URL del ecosistema.
 * No hay usuario de sesion ni modelo que autorizar: el alcance lo pone EcosystemNoticeService.
 */
class EcosystemNoticeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('bridge_system') === 'dashboard';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'correos' => ['required', 'array', 'min:1', 'max:50'],
            'correos.*' => ['required', 'string', 'email:rfc', 'max:255'],
            // Sin control ni formato Unicode (bidi, ancho cero, separadores de linea): se leen en la pantalla bloqueada.
            'titulo' => ['required', 'string', 'max:60', 'regex:/^[^\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+$/u'],
            'cuerpo' => ['required', 'string', 'max:120', 'regex:/^[^\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+$/u'],
            'url' => ['required', 'string', 'max:300', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || EcosystemUrl::normalize($value) === null) {
                    $fail('invalid');
                }
            }],
            'ref' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/D'],
        ];
    }

    public function normalizedUrl(): string
    {
        return (string) EcosystemUrl::normalize((string) $this->validated('url'));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['error' => 'invalid'], 422));
    }
}
