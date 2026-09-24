<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Http\Requests\Concerns\ValidatesRoleAndTeam;
use Illuminate\Foundation\Http\FormRequest;

class ApproveUserRequest extends FormRequest
{
    use ValidatesRoleAndTeam;

    public function authorize(): bool
    {
        return $this->user()?->can('approve', $this->route('user')) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->roleAndTeamRules();
    }
}
