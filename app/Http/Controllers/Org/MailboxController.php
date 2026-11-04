<?php

declare(strict_types=1);

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Http\Requests\Org\MailboxActionRequest;
use App\Http\Requests\Org\SaveMailboxRequest;
use App\Models\Coordination;
use App\Models\CoordinationMailbox;
use App\Services\MailboxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MailboxController extends Controller
{
    public function __construct(private readonly MailboxService $mailboxes) {}

    public function index(): View
    {
        $this->authorize('viewAny', CoordinationMailbox::class);

        return view('org.mailboxes.index', [
            'coordinations' => Coordination::query()->with(['station:id,name', 'mailbox'])->orderBy('name')->get(),
        ]);
    }

    public function edit(Coordination $coordination): View
    {
        $this->authorize($coordination->mailbox ? 'update' : 'create', $coordination->mailbox ?? CoordinationMailbox::class);

        return view('org.mailboxes.edit', [
            'coordination' => $coordination->load('station:id,name'),
            'mailbox' => $coordination->mailbox ?? new CoordinationMailbox(['port' => 993, 'encryption' => 'ssl', 'folder' => 'INBOX', 'active' => true]),
            'encryptions' => CoordinationMailbox::ENCRYPTIONS,
        ]);
    }

    public function update(SaveMailboxRequest $request, Coordination $coordination): RedirectResponse
    {
        $this->mailboxes->save($request->user(), $coordination, $request->payload());

        return redirect()->route('mailboxes.index')->with('status', 'mailbox-saved');
    }

    public function destroy(MailboxActionRequest $request, Coordination $coordination): RedirectResponse
    {
        $this->mailboxes->delete($request->user(), $coordination);

        return redirect()->route('mailboxes.index')->with('status', 'mailbox-deleted');
    }

    public function test(MailboxActionRequest $request, Coordination $coordination): RedirectResponse
    {
        $failure = $this->mailboxes->test($request->user(), $coordination);

        return $failure === null
            ? redirect()->route('mailboxes.index')->with('status', 'mailbox-test-ok')
            : redirect()->route('mailboxes.index')->with('error', __('mailboxes.errors.test_failed', ['reason' => $failure]));
    }
}
