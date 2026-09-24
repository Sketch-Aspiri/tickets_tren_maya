<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorConfirmRequest;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TwoFactorSetupController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $this->authorize('manageTwoFactor', $user);

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('profile.edit');
        }

        return view('auth.two-factor-setup', [
            ...$this->twoFactor->prepareSetup($user),
            'isRequired' => $user->requiresTwoFactor(),
        ]);
    }

    public function store(TwoFactorConfirmRequest $request): RedirectResponse
    {
        $user = $request->user();
        $this->authorize('manageTwoFactor', $user);

        $recoveryCodes = $this->twoFactor->confirmSetup($user, $request->validated('code'));

        if ($recoveryCodes === null) {
            return back()->withErrors(['code' => __('two_factor.errors.invalid_code')]);
        }

        $this->twoFactor->markVerifiedInSession($user);

        return redirect()->route('profile.edit')
            ->with('status', 'two-factor-enabled')
            ->with('recovery_codes', $recoveryCodes);
    }
}
