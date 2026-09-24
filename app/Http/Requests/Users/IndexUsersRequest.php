<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', User::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::enum(UserStatus::class)],
            'role' => ['nullable', 'string', Rule::enum(UserRole::class)],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
