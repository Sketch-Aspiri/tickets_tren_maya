<?php

use App\Http\Controllers\Internal\PortalAccountController;
use App\Http\Controllers\Internal\PortalAvatarController;
use App\Http\Controllers\Internal\PortalCatalogController;
use Illuminate\Support\Facades\Route;

/*
 * Puente con el portal (decision 99): solo de servidor a servidor, firmado con el secreto propio del portal
 * (VerifyPortalBridge). Registrado en bootstrap/app.php con el prefijo `interno/portal`.
 */
Route::get('catalogo', PortalCatalogController::class)->name('catalogo');
Route::get('cuentas', [PortalAccountController::class, 'index'])->name('cuentas.index');
Route::post('cuentas', [PortalAccountController::class, 'store'])->name('cuentas.store');

// Estado (activo/inactivo) y rol/contexto de una cuenta ya emparejada; "quitar acceso" = inactivo.
Route::put('cuentas/{portalUserId}', [PortalAccountController::class, 'update'])->name('cuentas.update');

// Foto de perfil unica: el portal la lee (herencia inicial) y la reparte.
Route::get('avatar', [PortalAvatarController::class, 'show'])->name('avatar.show');
Route::put('avatar', [PortalAvatarController::class, 'update'])->name('avatar.update');
Route::delete('avatar', [PortalAvatarController::class, 'destroy'])->name('avatar.destroy');
