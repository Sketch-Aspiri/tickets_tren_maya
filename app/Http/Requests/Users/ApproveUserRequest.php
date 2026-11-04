<?php

declare(strict_types=1);

namespace App\Http\Requests\Users;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Http\Requests\Concerns\ValidatesRoleAndTeam;
use Illuminate\Foundation\Http\FormRequest;

class ApproveUserRequest extends FormRequest
{
    use AuthorizesWithGate, ValidatesRoleAndTeam;

    public function authorize(): bool
    {
        return $this->authorizeAbility('approve', $this->route('user'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->roleAndTeamRules();
    }
}
