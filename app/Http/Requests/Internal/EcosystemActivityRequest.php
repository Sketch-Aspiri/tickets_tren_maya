<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Support\EcosystemUrl;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * `POST /interno/actividades` del puente con el Dashboard (decision 92). Ya paso por `VerifyBridge` (firma, fecha,
 * nonce e IP); aqui se autoriza que lo mande el Dashboard y se validan los limites. El equipo destino NO viaja en
 * la peticion: lo decide Tickets segun el evento (config/ecosistema.php). No hay usuario de sesion ni modelo que
 * autorizar.
 */
class EcosystemActivityRequest extends FormRequest
{
    // Saltos de linea y tabuladores si; ningun otro control ni caracter de formato Unicode (bidi, ancho cero, U+2028/9).
    private const PLAIN = '/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F\p{Cf}\p{Zl}\p{Zp}]+$/u';

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
            'evento' => ['required', 'string', Rule::in(array_keys((array) config('ecosistema.actividades.eventos')))],
            'estacion' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'ref' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9:._-]+$/D'],
            'titulo' => ['required', 'string', 'max:120', 'regex:/^[^\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+$/u'],
            'descripcion' => ['required', 'string', 'max:1000', 'regex:'.self::PLAIN],
            'url' => ['nullable', 'string', 'max:300', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || EcosystemUrl::normalize($value) === null) {
                    $fail('invalid');
                }
            }],
        ];
    }

    public function normalizedUrl(): ?string
    {
        $url = $this->validated('url');

        return is_string($url) ? EcosystemUrl::normalize($url) : null;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['error' => 'invalid'], 422));
    }
}
