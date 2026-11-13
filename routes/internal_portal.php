<?php

use App\Http\Controllers\Internal\PortalAccountController;
use App\Http\Controllers\Internal\PortalCatalogController;
use Illuminate\Support\Facades\Route;

/*
 * Puente con el portal (decision 99): solo de servidor a servidor, firmado con el secreto propio del portal
 * (VerifyPortalBridge). Registrado en bootstrap/app.php con el prefijo `interno/portal`.
 */
Route::get('catalogo', PortalCatalogController::class)->name('catalogo');
Route::get('cuentas', [PortalAccountController::class, 'index'])->name('cuentas.index');
Route::post('cuentas', [PortalAccountController::class, 'store'])->name('cuentas.store');
