<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\UserRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly UserRegistrationService $registration) {}

    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * La cuenta queda `pending`, sin rol y sin sesion iniciada, hasta que el jefe de zona la apruebe.
     */
    public function store(RegisterRequest $request): RedirectResponse
    {
        /** @var array{name: string, email: string, password: string} $data */
        $data = $request->safe()->only(['name', 'email', 'password']);

        $this->registration->register($data);

        return redirect()->route('login')->with('status', __('auth.registered'));
    }
}
