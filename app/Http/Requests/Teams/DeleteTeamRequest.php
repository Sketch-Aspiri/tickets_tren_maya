<?php

declare(strict_types=1);

namespace App\Http\Requests\Teams;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use Illuminate\Foundation\Http\FormRequest;

class DeleteTeamRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('delete', $this->route('team'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
