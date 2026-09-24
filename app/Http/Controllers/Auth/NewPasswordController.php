<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\UserProfileService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    public function __construct(private readonly UserProfileService $profile) {}

    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    public function store(ResetPasswordRequest $request): RedirectResponse
    {
        $status = Password::reset(
            $request->safe()->only('email', 'password', 'token'),
            function (User $user) use ($request): void {
                $this->profile->resetPassword($user, $request->validated('password'));

                event(new PasswordReset($user));
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', __($status))
            : back()->withInput($request->safe()->only('email'))->withErrors(['email' => __($status)]);
    }
}
