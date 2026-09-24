<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tickets;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\StoreAttachmentRequest;
use App\Models\Ticket;
use App\Services\AttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;

class TicketAttachmentController extends Controller
{
    public function __construct(private readonly AttachmentService $attachments) {}

    public function store(StoreAttachmentRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('attach', $ticket);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $this->attachments->store($request->user(), $ticket, $file);

        return redirect()->route('tickets.show', $ticket)->withFragment('adjuntos')->with('status', 'attachment-added');
    }
}
