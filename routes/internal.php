<?php

use App\Http\Controllers\Internal\EcosystemNoticeController;
use App\Http\Controllers\Internal\PendingSummaryController;
use Illuminate\Support\Facades\Route;

/*
 * Puente interno con el Dashboard (decision 89): solo de servidor a servidor. Sin sesion, cookies ni CSRF;
 * cada peticion la autentica VerifyBridge (firma HMAC, fecha, nonce de un solo uso e IP). Se registra en
 * bootstrap/app.php con el prefijo `interno` y `throttle:ecosystem-bridge`.
 */
Route::get('pendientes', PendingSummaryController::class)->name('pendientes');

// Avisos del Dashboard (campana + Web Push), decision 91.
Route::post('avisos', EcosystemNoticeController::class)->name('avisos');
