<?php

use App\Http\Controllers\AccountStatusController;
use App\Http\Controllers\Activities\ActivityAssignmentController;
use App\Http\Controllers\Activities\ActivityAttachmentController;
use App\Http\Controllers\Activities\ActivityCommentController;
use App\Http\Controllers\Activities\ActivityController;
use App\Http\Controllers\Activities\ActivityExportController;
use App\Http\Controllers\Activities\ActivityTemplateController;
use App\Http\Controllers\Activities\ActivityTransitionController;
use App\Http\Controllers\Activities\SubtaskController;
use App\Http\Controllers\Attachments\AttachmentController;
use App\Http\Controllers\Audit\AuditLogController;
use App\Http\Controllers\Auth\TwoFactorSettingsController;
use App\Http\Controllers\Categories\CategoryController;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\Chat\ChatMessageController;
use App\Http\Controllers\Chat\ChatUnreadController;
use App\Http\Controllers\Chat\ChatUserSearchController;
use App\Http\Controllers\Dashboard\TrackingPanelController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IncomingEmailAttachmentController;
use App\Http\Controllers\IncomingEmailController;
use App\Http\Controllers\IncomingEmailConversionController;
use App\Http\Controllers\IncomingEmailDiscardController;
use App\Http\Controllers\Notifications\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Tickets\TicketAssignmentController;
use App\Http\Controllers\Tickets\TicketAttachmentController;
use App\Http\Controllers\Tickets\TicketCommentController;
use App\Http\Controllers\Tickets\TicketController;
use App\Http\Controllers\Tickets\TicketExportController;
use App\Http\Controllers\Tickets\TicketPendingController;
use App\Http\Controllers\Tickets\TicketTransitionController;
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

    // Categorias: solo jefe de zona (CategoryPolicy).
    Route::resource('categories', CategoryController::class)->except('show');

    // Tickets (TicketPolicy: permiso Spatie + alcance por rol; fuera de alcance responde 404).
    // `tickets/pending` va antes de `tickets/{ticket}` para que no lo capture el parametro.
    Route::get('tickets/pending', TicketPendingController::class)->name('tickets.pending');
    // Exportacion a Excel del listado filtrado: permiso `exports.create`, limite de tasa y bitacora `exported`.
    Route::get('tickets/export', TicketExportController::class)->middleware('throttle:export')->name('tickets.export');
    Route::post('tickets', [TicketController::class, 'store'])->middleware('throttle:ticket-create')->name('tickets.store');
    Route::resource('tickets', TicketController::class)->except('store')->middlewareFor(['update', 'destroy'], 'throttle:ticket-write');

    Route::middleware('throttle:ticket-write')->prefix('tickets/{ticket}')->name('tickets.')->group(function () {
        Route::post('transition', [TicketTransitionController::class, 'store'])->name('transition');
        Route::put('assignments', [TicketAssignmentController::class, 'update'])->name('assignments.update');
        Route::delete('assignments', [TicketAssignmentController::class, 'destroy'])->name('assignments.destroy');
        Route::post('take', [TicketAssignmentController::class, 'take'])->name('take');
    });

    Route::post('tickets/{ticket}/comments', [TicketCommentController::class, 'store'])
        ->middleware('throttle:ticket-comment')
        ->name('tickets.comments.store');
    Route::post('tickets/{ticket}/attachments', [TicketAttachmentController::class, 'store'])
        ->middleware('throttle:ticket-upload')
        ->name('tickets.attachments.store');

    // Actividades (ActivityPolicy: permiso Spatie + alcance por rol; fuera de alcance responde 404).
    // Sin bolsa, "tomar" ni "devolver a la bolsa": las actividades se asignan siempre de forma explicita.
    // Exportacion a Excel del listado filtrado (antes del resource: `activities/{activity}` no debe capturarla).
    Route::get('activities/export', ActivityExportController::class)->middleware('throttle:export')->name('activities.export');
    // Plantillas de recurrencia (nunca en el listado principal): mismo motivo, `activities/templates` va antes
    // del resource. `restore` necesita `withTrashed()` para que el binding implicito encuentre una fila eliminada.
    Route::get('activities/templates', [ActivityTemplateController::class, 'index'])->name('activities.templates.index');
    Route::post('activities/templates/{activity}/restore', [ActivityTemplateController::class, 'restore'])
        ->middleware('throttle:activity-write')
        ->withTrashed()
        ->name('activities.templates.restore');
    Route::post('activities', [ActivityController::class, 'store'])->middleware('throttle:activity-create')->name('activities.store');
    Route::resource('activities', ActivityController::class)->except('store')->middlewareFor(['update', 'destroy'], 'throttle:activity-write');

    Route::middleware('throttle:activity-write')->prefix('activities/{activity}')->name('activities.')->group(function () {
        Route::post('transition', [ActivityTransitionController::class, 'store'])->name('transition');
        Route::put('assignments', [ActivityAssignmentController::class, 'update'])->name('assignments.update');

        // Subtareas anidadas con `scopeBindings`: una subtarea de otra actividad responde 404.
        Route::scopeBindings()->prefix('subtasks')->name('subtasks.')->group(function () {
            Route::post('/', [SubtaskController::class, 'store'])->name('store');
            Route::put('{subtask}', [SubtaskController::class, 'update'])->name('update');
            Route::post('{subtask}/done', [SubtaskController::class, 'done'])->name('done');
            Route::delete('{subtask}', [SubtaskController::class, 'destroy'])->name('destroy');
        });
    });

    Route::post('activities/{activity}/comments', [ActivityCommentController::class, 'store'])
        ->middleware('throttle:activity-comment')
        ->name('activities.comments.store');
    Route::post('activities/{activity}/attachments', [ActivityAttachmentController::class, 'store'])
        ->middleware('throttle:activity-upload')
        ->name('activities.attachments.store');

    // Panel de seguimiento (Sprint 5): jefe global, coordinador su equipo; el empleado recibe 403 (habilidad
    // `view-dashboard`, permiso `dashboard.view`). Solo lectura, con limite de tasa por usuario.
    Route::get('tracking', TrackingPanelController::class)->middleware('throttle:dashboard')->name('tracking.index');

    // Visor de la bitacora de auditoria: solo jefe (AuditLogPolicy, permiso `audit.view`). Solo lectura.
    Route::get('audit-log', [AuditLogController::class, 'index'])->middleware('throttle:audit-view')->name('audit.index');

    // Bandeja de correos entrantes (IncomingEmailPolicy: permiso `emails.view`/`emails.manage`, sin
    // recorte por equipo). Revisar = descartar (con motivo) o convertir en Actividad; nunca se borra.
    Route::prefix('incoming-emails')->name('incoming-emails.')->group(function () {
        Route::get('/', [IncomingEmailController::class, 'index'])->name('index');
        Route::get('{incomingEmail}', [IncomingEmailController::class, 'show'])->name('show');
        Route::post('{incomingEmail}/discard', [IncomingEmailDiscardController::class, 'store'])
            ->middleware('throttle:email-write')->name('discard');
        Route::get('{incomingEmail}/convert', [IncomingEmailConversionController::class, 'create'])->name('convert');
        Route::post('{incomingEmail}/convert', [IncomingEmailConversionController::class, 'store'])
            ->middleware('throttle:email-write')->name('convert.store');
    });

    Route::get('incoming-email-attachments/{incomingEmailAttachment}', [IncomingEmailAttachmentController::class, 'download'])
        ->middleware('throttle:attachment-download')->name('incoming-email-attachments.download');

    // Notificaciones del propio usuario (asignaciones, avisos y mensajes de chat sin leer).
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('summary', [NotificationController::class, 'summary'])->middleware('throttle:notifications-poll')->name('summary');
        Route::post('read-all', [NotificationController::class, 'readAll'])->middleware('throttle:notifications-write')->name('read-all');
        Route::get('{notification}', [NotificationController::class, 'open'])->middleware('throttle:notifications-write')->name('open');
    });

    // Chat interno (1 a 1 y canal de equipo). Polling JSON; las rutas estaticas van antes de `{conversation}`.
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('/', [ChatController::class, 'index'])->name('index');
        Route::post('direct', [ChatController::class, 'startDirect'])->middleware('throttle:chat-start')->name('direct');
        Route::get('users', ChatUserSearchController::class)->middleware('throttle:chat-search')->name('users');
        Route::get('list', [ChatController::class, 'list'])->middleware('throttle:chat-poll')->name('list');
        Route::get('unread', ChatUnreadController::class)->middleware('throttle:chat-poll')->name('unread');
        Route::get('{conversation}', [ChatController::class, 'show'])->name('show');
        Route::get('{conversation}/messages', [ChatMessageController::class, 'index'])->middleware('throttle:chat-poll')->name('messages.index');
        Route::post('{conversation}/messages', [ChatMessageController::class, 'store'])->middleware('throttle:chat-send')->name('messages.store');
    });

    // Los adjuntos solo se sirven por aqui (disco privado); nunca hay URL publica.
    Route::get('attachments/{attachment}', [AttachmentController::class, 'download'])
        ->middleware('throttle:attachment-download')
        ->name('attachments.download');
    // Previsualizacion en linea SOLO de imagenes png/jpg (mismo permiso que la descarga).
    Route::get('attachments/{attachment}/preview', [AttachmentController::class, 'preview'])
        ->middleware('throttle:attachment-download')
        ->name('attachments.preview');
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])
        ->middleware('throttle:ticket-write')
        ->name('attachments.destroy');
});

require __DIR__.'/auth.php';
