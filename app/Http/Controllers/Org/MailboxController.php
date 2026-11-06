<?php

declare(strict_types=1);

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Http\Requests\Org\MailboxActionRequest;
use App\Http\Requests\Org\SaveMailboxRequest;
use App\Models\Coordination;
use App\Models\CoordinationMailbox;
use App\Notifications\NewIncomingEmailToReview;
use App\Services\MailboxPoller;
use App\Services\MailboxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Notification;
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
        $this->authorize('manageInbox', $coordination);

        return view('org.mailboxes.edit', [
            'coordination' => $coordination->load('station:id,name'),
            'mailbox' => $coordination->mailbox ?? new CoordinationMailbox(['port' => 993, 'encryption' => 'ssl', 'folder' => 'INBOX', 'active' => true]),
            'encryptions' => CoordinationMailbox::ENCRYPTIONS,
        ]);
    }

    public function update(SaveMailboxRequest $request, Coordination $coordination): RedirectResponse
    {
        $this->mailboxes->save($request->user(), $coordination, $request->payload());

        return redirect()->to($this->home())->with('status', 'mailbox-saved');
    }

    public function destroy(MailboxActionRequest $request, Coordination $coordination): RedirectResponse
    {
        $this->mailboxes->delete($request->user(), $coordination);

        return redirect()->to($this->home())->with('status', 'mailbox-deleted');
    }

    public function test(MailboxActionRequest $request, Coordination $coordination): RedirectResponse
    {
        $failure = $this->mailboxes->test($request->user(), $coordination);

        return $failure === null
            ? redirect()->to($this->home())->with('status', 'mailbox-test-ok')
            : redirect()->to($this->home())->with('error', __('mailboxes.errors.test_failed', ['reason' => $failure]));
    }

    /**
     * "Sincronizar ahora": descarga los correos nuevos SOLO del buzon de esta coordinacion y avisa a quienes revisan.
     */
    public function sync(MailboxActionRequest $request, Coordination $coordination, MailboxPoller $poller): RedirectResponse
    {
        $mailbox = $coordination->mailbox;

        if ($mailbox === null || ! $mailbox->active) {
            return redirect()->to($this->home())->with('error', __('mailboxes.errors.not_configured'));
        }

        $outcome = $poller->poll($mailbox);

        if ($outcome['result'] === null) {
            return redirect()->to($this->home())->with('error', __('mailboxes.errors.sync_failed', ['reason' => (string) $mailbox->fresh()?->last_error]));
        }

        if ($outcome['result']['created'] > 0) {
            Notification::send($coordination->approvers(), new NewIncomingEmailToReview($outcome['result']['created']));
        }

        return redirect()->to($this->home())->with('notice', __('mailboxes.synced', ['count' => $outcome['result']['created']]));
    }

    /**
     * El administrador vuelve al listado global de buzones; jefe y coordinador, a "Mi bandeja".
     */
    private function home(): string
    {
        return request()->user()->can('viewAny', CoordinationMailbox::class) ? route('mailboxes.index') : route('inbox-settings.index');
    }
}
