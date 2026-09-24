<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:register');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.store');
});

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// Cuenta activa requerida, pero todavia sin el desafio 2FA (es lo que estas rutas resuelven).
// `no-store`: la pantalla de alta muestra el secreto TOTP y no debe quedar en cache.
Route::middleware(['auth', 'account.active', 'no-store'])->prefix('two-factor')->name('two-factor.')->group(function () {
    Route::get('setup', [TwoFactorSetupController::class, 'show'])->name('setup');
    Route::post('setup', [TwoFactorSetupController::class, 'store'])
        ->middleware('throttle:two-factor')
        ->name('confirm');

    Route::get('challenge', [TwoFactorChallengeController::class, 'show'])->name('challenge');
    Route::post('challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:two-factor')
        ->name('verify');
});

Route::middleware(['auth', 'account.active', 'two-factor'])->group(function () {
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');
});
