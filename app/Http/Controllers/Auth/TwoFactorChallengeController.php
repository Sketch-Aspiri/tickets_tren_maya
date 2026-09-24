<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TwoFactorChallengeController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $this->authorize('manageTwoFactor', $user);

        if (! $user->hasTwoFactorEnabled() || $this->twoFactor->isVerifiedInSession($user)) {
            return redirect()->route('dashboard');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(TwoFactorChallengeRequest $request): RedirectResponse
    {
        $user = $request->user();
        $this->authorize('manageTwoFactor', $user);

        $recoveryCode = $request->validated('recovery_code');

        $passed = $recoveryCode !== null
            ? $this->twoFactor->passesRecoveryChallenge($user, $recoveryCode)
            : $this->twoFactor->passesChallenge($user, (string) $request->validated('code'));

        if (! $passed) {
            $field = $recoveryCode !== null ? 'recovery_code' : 'code';

            return back()->withErrors([$field => __('two_factor.errors.invalid_code')]);
        }

        $this->twoFactor->markVerifiedInSession($user);

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
