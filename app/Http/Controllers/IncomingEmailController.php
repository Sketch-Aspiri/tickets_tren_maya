<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Emails\IndexIncomingEmailsRequest;
use App\Models\Coordination;
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

        $coordinationId = $request->coordinationFilter();

        // Bandejas (una por coordinación/buzón) visibles para este usuario, con su conteo de pendientes.
        $pending = IncomingEmail::query()
            ->visibleTo($request->user())
            ->where('status', 'pending_review')
            ->selectRaw('incoming_emails.coordination_id, count(*) as total')
            ->groupBy('incoming_emails.coordination_id')
            ->pluck('total', 'coordination_id');

        $mailboxes = Coordination::query()
            ->with('station:id,name')
            ->when(! $request->user()->seesAllTeams(), fn ($q) => $q->whereIn('coordinations.id', $request->user()->coordinationIdsQuery()))
            ->orderBy('name')
            ->get(['id', 'name', 'station_id']);

        if ($coordinationId !== null && ! $mailboxes->contains('id', $coordinationId)) {
            $coordinationId = null;
        }

        $emails = IncomingEmail::query()
            ->visibleTo($request->user())
            ->with(['attachments', 'coordination:id,name,station_id', 'coordination.station:id,name'])
            ->when($coordinationId !== null, fn ($query) => $query->where('incoming_emails.coordination_id', $coordinationId))
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->orderByDesc('received_at')
            ->paginate((int) config('mail_ingestion.per_page'))
            ->withQueryString();

        return view('incoming-emails.index', [
            'emails' => $emails,
            'status' => $status,
            'coordinationId' => $coordinationId,
            'mailboxes' => $mailboxes,
            'pendingByMailbox' => $pending,
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
