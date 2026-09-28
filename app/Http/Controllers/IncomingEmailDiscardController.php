<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Emails\DiscardIncomingEmailRequest;
use App\Models\IncomingEmail;
use App\Services\IncomingEmailReviewService;
use Illuminate\Http\RedirectResponse;

class IncomingEmailDiscardController extends Controller
{
    public function store(
        DiscardIncomingEmailRequest $request,
        IncomingEmail $incomingEmail,
        IncomingEmailReviewService $reviewService,
    ): RedirectResponse {
        $reviewService->discard($request->user(), $incomingEmail, (string) $request->validated('reason'));

        return redirect()->route('incoming-emails.index')->with('status', 'incoming-email-discarded');
    }
}
