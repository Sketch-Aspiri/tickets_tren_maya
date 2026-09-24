<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Enums\UserRole;
use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Support\Dashboard\DashboardFilters;
use App\Support\LocalTime;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros del panel de seguimiento (query string). Autoriza ANTES de validar. Los filtros solo estrechan el
 * alcance (DashboardScope): además, un coordinador que envía el equipo o la persona de otro equipo recibe un
 * error de validación en lugar de un resultado vacío o ampliado.
 */
class ShowTrackingPanelRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('view-dashboard', []);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();
        $isCoordinator = $user?->roleEnum() === UserRole::Coordinador;

        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.LocalTime::today()],
            'team_id' => ['nullable', 'integer', $isCoordinator
                ? Rule::in([(int) $user->team_id])
                : Rule::exists('teams', 'id')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')
                ->when($isCoordinator, fn ($rule) => $rule->where('team_id', (int) $user->team_id))],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
        ];
    }

    /**
     * El orden y el tope del rango se validan sobre las fechas ya resueltas (con los valores por defecto).
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $filters = $this->filters();

            if ($filters->from > $filters->to) {
                $validator->errors()->add('from', __('tracking.validation.range_order'));
            } elseif ($filters->days() > (int) config('tickets.dashboard.max_period_days')) {
                $validator->errors()->add('from', __('tracking.validation.range_too_long', ['days' => (int) config('tickets.dashboard.max_period_days')]));
            }
        }];
    }

    public function filters(): DashboardFilters
    {
        /** @var array{from?: ?string, to?: ?string, team_id?: ?int, user_id?: ?int, category_id?: ?int} $data */
        $data = $this->safe()->only(['from', 'to', 'team_id', 'user_id', 'category_id']);

        return DashboardFilters::fromValidated($data);
    }
}
