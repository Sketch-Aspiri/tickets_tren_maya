<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tickets;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\StoreCommentRequest;
use App\Models\Ticket;
use App\Services\CommentService;
use Illuminate\Http\RedirectResponse;

class TicketCommentController extends Controller
{
    public function __construct(private readonly CommentService $comments) {}

    public function store(StoreCommentRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('comment', $ticket);

        $this->comments->add($request->user(), $ticket, (string) $request->validated('body'));

        return redirect()->route('tickets.show', $ticket)->withFragment('comentarios')->with('status', 'comment-added');
    }
}
