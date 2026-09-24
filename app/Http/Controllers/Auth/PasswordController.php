<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Services\UserProfileService;
use Illuminate\Http\RedirectResponse;

class PasswordController extends Controller
{
    public function __construct(private readonly UserProfileService $profile) {}

    public function update(UpdatePasswordRequest $request): RedirectResponse
    {
        $this->authorize('updateProfile', $request->user());

        $this->profile->changePassword(
            $request->user(),
            $request->validated('password'),
            $request->session()->getId(),
        );

        return back()->with('status', 'password-updated');
    }
}
