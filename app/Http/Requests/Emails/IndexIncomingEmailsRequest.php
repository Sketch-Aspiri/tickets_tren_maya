<?php

declare(strict_types=1);

namespace App\Http\Requests\Emails;

use App\Models\IncomingEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtro de la bandeja de correos entrantes. Por defecto solo lo pendiente de revisión;
 * `status=all` quita el filtro (útil para ver todo el histórico, incluidos convertidos/descartados).
 */
class IndexIncomingEmailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', IncomingEmail::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(['pending_review', 'converted', 'discarded', 'all'])],
        ];
    }

    public function statusFilter(): string
    {
        return $this->validated('status') ?? 'pending_review';
    }
}
