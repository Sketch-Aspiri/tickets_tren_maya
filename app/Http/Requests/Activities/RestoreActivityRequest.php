<?php

declare(strict_types=1);

namespace App\Http\Requests\Activities;

use App\Http\Requests\Concerns\AuthorizesWithGate;
use Illuminate\Foundation\Http\FormRequest;

class RestoreActivityRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        return $this->authorizeAbility('restore', $this->route('activity'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
