<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\TwoFactorService;
use App\Services\UserProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly UserProfileService $profile,
    ) {}

    public function edit(Request $request): View
    {
        $user = $request->user();
        $this->authorize('updateProfile', $user);

        return view('profile.edit', [
            'user' => $user,
            'remainingRecoveryCodes' => $this->twoFactor->remainingRecoveryCodes($user),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $this->authorize('updateProfile', $request->user());

        $this->profile->updateName($request->user(), $request->validated('name'));

        return redirect()->route('profile.edit')->with('status', 'profile-updated');
    }
}
