<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DisableTwoFactorRequest;
use App\Http\Requests\Auth\RegenerateRecoveryCodesRequest;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;

class TwoFactorSettingsController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function destroy(DisableTwoFactorRequest $request): RedirectResponse
    {
        $this->authorize('disableTwoFactor', $request->user());

        $this->twoFactor->disable($request->user());

        return redirect()->route('profile.edit')->with('status', 'two-factor-disabled');
    }

    public function regenerateRecoveryCodes(RegenerateRecoveryCodesRequest $request): RedirectResponse
    {
        $this->authorize('manageTwoFactor', $request->user());

        $codes = $this->twoFactor->regenerateRecoveryCodes($request->user());

        return redirect()->route('profile.edit')
            ->with('status', 'recovery-codes-regenerated')
            ->with('recovery_codes', $codes);
    }
}
