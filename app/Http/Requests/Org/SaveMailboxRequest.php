<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

use App\Contracts\HostResolver;
use App\Http\Requests\Concerns\AuthorizesWithGate;
use App\Models\CoordinationMailbox;
use App\Rules\PublicMailHost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMailboxRequest extends FormRequest
{
    use AuthorizesWithGate;

    public function authorize(): bool
    {
        $mailbox = $this->route('coordination')->mailbox ?? new CoordinationMailbox;

        return $this->authorizeAbility($mailbox->exists ? 'update' : 'create', $mailbox->exists ? $mailbox : CoordinationMailbox::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $hasPassword = $this->route('coordination')->mailbox !== null;

        return [
            'host' => ['required', 'string', 'max:253', new PublicMailHost(app(HostResolver::class))],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', 'string', Rule::in(CoordinationMailbox::ENCRYPTIONS)],
            'username' => ['required', 'string', 'max:255'],
            // Al editar, vacio conserva la contrasena actual.
            'password' => [$hasPassword ? 'nullable' : 'required', 'string', 'max:255'],
            'folder' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9 ._\/-]+$/'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array{host: string, port: int, encryption: string, username: string, password: ?string, folder: string, active: bool}
     */
    public function payload(): array
    {
        return [
            'host' => (string) $this->validated('host'),
            'port' => (int) $this->validated('port'),
            'encryption' => (string) $this->validated('encryption'),
            'username' => trim((string) $this->validated('username')),
            'password' => $this->validated('password'),
            'folder' => (string) $this->validated('folder'),
            'active' => $this->boolean('active', true),
        ];
    }
}
