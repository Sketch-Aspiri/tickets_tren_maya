<?php

declare(strict_types=1);

namespace App\Http\Requests\Teams;

use Illuminate\Foundation\Http\FormRequest;

class DeleteTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delete', $this->route('team')) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
