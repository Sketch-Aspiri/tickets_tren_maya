<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Emails\IndexIncomingEmailsRequest;
use App\Models\IncomingEmail;
use Illuminate\View\View;

/**
 * Bandeja de correos entrantes: jefe de zona, administrador y coordinador (permiso `emails.view`),
 * sin recorte por equipo (un correo externo no tiene equipo hasta que alguien lo convierte).
 */
class IncomingEmailController extends Controller
{
    public function index(IndexIncomingEmailsRequest $request): View
    {
        $this->authorize('viewAny', IncomingEmail::class);

        $status = $request->statusFilter();

        $emails = IncomingEmail::query()
            ->with('attachments')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->orderByDesc('received_at')
            ->paginate((int) config('mail_ingestion.per_page'))
            ->withQueryString();

        return view('incoming-emails.index', [
            'emails' => $emails,
            'status' => $status,
        ]);
    }

    public function show(IncomingEmail $incomingEmail): View
    {
        $this->authorize('view', $incomingEmail);

        $incomingEmail->load(['attachments', 'reviewer', 'activity']);

        return view('incoming-emails.show', [
            'email' => $incomingEmail,
        ]);
    }
}
