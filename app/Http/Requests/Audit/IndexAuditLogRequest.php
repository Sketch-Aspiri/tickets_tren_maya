<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Services\AuditLogService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;

/**
 * Filtros del visor de bitácora (solo jefe). Autoriza ANTES de validar. El tipo de sujeto sale de una lista
 * blanca; el evento solo admite el alfabeto de los nombres de evento (minúsculas, dígitos y guion bajo).
 */
class IndexAuditLogRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('viewAny', ActivityLogEntry::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'causer_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'event' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'subject' => ['nullable', 'string', Rule::in(array_keys(AuditLogService::SUBJECT_TYPES))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $from = $this->input('from');
            $to = $this->input('to');

            if ($validator->errors()->isEmpty() && is_string($from) && is_string($to) && $from > $to) {
                $validator->errors()->add('from', __('audit.validation.range_order'));
            }
        }];
    }

    /**
     * @return array{causer_id?: ?int, event?: ?string, subject?: ?string, from?: ?string, to?: ?string, q?: ?string}
     */
    public function filters(): array
    {
        /** @var array{causer_id?: ?int, event?: ?string, subject?: ?string, from?: ?string, to?: ?string, q?: ?string} */
        return $this->safe()->only(['causer_id', 'event', 'subject', 'from', 'to', 'q']);
    }
}
