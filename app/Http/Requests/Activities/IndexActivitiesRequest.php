<?php

declare(strict_types=1);

namespace App\Http\Requests\Activities;

use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Services\ActivityListingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexActivitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Activity::class) ?? false;
    }

    /**
     * Filtros por query string. Nunca ensanchan el alcance: la consulta siempre parte de `visibleTo`.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::enum(TicketStatus::class)],
            'priority' => ['nullable', 'string', Rule::enum(Priority::class)],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'responsible_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'overdue' => ['nullable', 'boolean'],
            'kind' => ['nullable', 'string', Rule::in(ActivityListingService::KINDS)],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', Rule::in(ActivityListingService::SORTABLE)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            ...$this->safe()->only(['status', 'priority', 'category_id', 'responsible_id', 'team_id', 'kind', 'q', 'sort', 'direction']),
            'overdue' => $this->boolean('overdue'),
        ];
    }
}
