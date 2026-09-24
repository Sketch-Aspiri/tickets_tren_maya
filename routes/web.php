<?php

use App\Http\Controllers\AccountStatusController;
use App\Http\Controllers\Auth\TwoFactorSettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Users\UserApprovalController;
use App\Http\Controllers\Users\UserController;
use App\Http\Controllers\Users\UserStatusController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// Unica ruta autenticada accesible para cuentas pendientes/inactivas (ademas del logout).
Route::get('account/status', [AccountStatusController::class, 'show'])
    ->middleware('auth')
    ->name('account.status');

// Toda la aplicacion: sesion + cuenta activa con rol + 2FA (configurado y superado en la sesion).
Route::middleware(['auth', 'account.active', 'two-factor'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // `no-store`: tras activar/regenerar el 2FA esta pagina muestra los codigos de recuperacion una vez.
    Route::get('profile', [ProfileController::class, 'edit'])->middleware('no-store')->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::delete('two-factor', [TwoFactorSettingsController::class, 'destroy'])
        ->middleware('throttle:two-factor')
        ->name('two-factor.destroy');
    Route::post('two-factor/recovery-codes', [TwoFactorSettingsController::class, 'regenerateRecoveryCodes'])
        ->middleware('throttle:two-factor')
        ->name('two-factor.recovery-codes');

    // Solo jefe de zona (Policies: UserPolicy / TeamPolicy).
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('{user}', [UserController::class, 'show'])->name('show');
        Route::put('{user}', [UserController::class, 'update'])->name('update');
        Route::post('{user}/approve', [UserApprovalController::class, 'approve'])->name('approve');
        Route::post('{user}/reject', [UserApprovalController::class, 'reject'])->name('reject');
        Route::post('{user}/activate', [UserStatusController::class, 'activate'])->name('activate');
        Route::post('{user}/deactivate', [UserStatusController::class, 'deactivate'])->name('deactivate');
    });

    Route::resource('teams', TeamController::class)->except('show');
});

require __DIR__.'/auth.php';
