<?php

declare(strict_types=1);

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Http\Requests\Org\InboxActionRequest;
use App\Http\Requests\Org\SaveSpreadsheetSourceRequest;
use App\Http\Requests\Org\UploadSheetRequest;
use App\Models\Coordination;
use App\Models\SpreadsheetSource;
use App\Services\AuditLogger;
use App\Services\SheetInbox\MicrosoftPersonalAuth;
use App\Services\SheetInbox\SheetInboxException;
use App\Services\SheetInbox\SheetInboxImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Hoja de Excel (OneDrive personal) que alimenta la bandeja de una coordinación: configurar, conectar la cuenta de
 * Microsoft (código + PKCE, `state` de un solo uso en la sesión), sincronizar y quitar.
 */
class SpreadsheetSourceController extends Controller
{
    private const SESSION_KEY = 'sheet_oauth';

    public function __construct(
        private readonly MicrosoftPersonalAuth $auth,
        private readonly AuditLogger $audit,
    ) {}

    public function edit(Coordination $coordination): View
    {
        $this->authorize('manageInbox', $coordination);

        return view('org.sheet-sources.edit', [
            'coordination' => $coordination->load('station:id,name'),
            'source' => $coordination->spreadsheetSource ?? new SpreadsheetSource(['header_row' => 1, 'active' => true, 'name' => 'Bandeja Excel']),
            'configured' => $this->auth->isConfigured(),
        ]);
    }

    public function update(SaveSpreadsheetSourceRequest $request, Coordination $coordination): RedirectResponse
    {
        $source = $coordination->spreadsheetSource ?? new SpreadsheetSource(['coordination_id' => $coordination->getKey()]);
        $isNew = ! $source->exists;
        $previousUrl = $source->share_url;
        $source->fill($request->payload())->save();

        $this->audit->record('sheet_sources', $isNew ? 'created' : 'updated', $coordination, $request->user(), extra: [
            'url_changed' => ! $isNew && $previousUrl !== $source->share_url,
        ]);

        return redirect()->to($this->home())->with('status', 'sheet-source-saved');
    }

    public function destroy(InboxActionRequest $request, Coordination $coordination): RedirectResponse
    {
        $coordination->spreadsheetSource?->delete();
        $this->audit->record('sheet_sources', 'deleted', $coordination, $request->user());

        return redirect()->to($this->home())->with('status', 'sheet-source-deleted');
    }

    /**
     * Manda a iniciar sesión en Microsoft. El `state` (aleatorio) y el verificador PKCE quedan solo en la sesión.
     */
    public function connect(InboxActionRequest $request, Coordination $coordination): RedirectResponse
    {
        if ($coordination->spreadsheetSource === null) {
            return redirect()->route('sheet-sources.edit', $coordination)->with('error', __('sheet_sources.errors.configure_first'));
        }

        try {
            $state = Str::random(40);
            $verifier = MicrosoftPersonalAuth::newVerifier();
            $url = $this->auth->authorizationUrl($state, $verifier);
        } catch (SheetInboxException $exception) {
            return redirect()->to($this->home())->with('error', $exception->getMessage());
        }

        $request->session()->put(self::SESSION_KEY, ['state' => $state, 'verifier' => $verifier, 'coordination_id' => (int) $coordination->getKey()]);

        return redirect()->away($url);
    }

    /**
     * Regreso de Microsoft: valida el `state`, vuelve a autorizar sobre la coordinación guardada y canjea el código.
     */
    public function callback(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull(self::SESSION_KEY);
        $state = $request->query('state');

        if (! is_array($pending) || ! is_string($state) || ! hash_equals((string) ($pending['state'] ?? ''), $state)) {
            return redirect()->to($this->home())->with('error', __('sheet_sources.errors.bad_state'));
        }

        $coordination = Coordination::query()->findOrFail((int) $pending['coordination_id']);
        Gate::authorize('manageInbox', $coordination);
        $source = $coordination->spreadsheetSource;
        $code = $request->query('code');

        if ($source === null || ! is_string($code) || $code === '') {
            return redirect()->to($this->home())->with('error', __('sheet_sources.errors.denied'));
        }

        try {
            $this->auth->connect($source, $code, (string) $pending['verifier']);
        } catch (SheetInboxException $exception) {
            return redirect()->to($this->home())->with('error', $exception->getMessage());
        }

        $this->audit->record('sheet_sources', 'connected', $coordination, $request->user(), extra: ['account' => $source->account_name]);

        return redirect()->to($this->home())->with('status', 'sheet-source-connected');
    }

    public function disconnect(InboxActionRequest $request, Coordination $coordination): RedirectResponse
    {
        if ($source = $coordination->spreadsheetSource) {
            $this->auth->disconnect($source);
            $this->audit->record('sheet_sources', 'disconnected', $coordination, $request->user());
        }

        return redirect()->to($this->home())->with('status', 'sheet-source-disconnected');
    }

    public function sync(InboxActionRequest $request, Coordination $coordination, SheetInboxImporter $importer): RedirectResponse
    {
        $source = $coordination->spreadsheetSource;

        if ($source === null || ! $source->active) {
            return redirect()->to($this->home())->with('error', __('sheet_sources.errors.configure_first'));
        }

        try {
            $result = $importer->import($source);
        } catch (SheetInboxException $exception) {
            return redirect()->to($this->home())->with('error', $exception->getMessage());
        }

        return redirect()->to($this->home())->with('notice', __('sheet_sources.synced', $result));
    }

    /**
     * Carga manual del libro: importa sus filas nuevas a la bandeja sin necesidad de conectar Microsoft.
     */
    public function upload(UploadSheetRequest $request, Coordination $coordination, SheetInboxImporter $importer): RedirectResponse
    {
        $source = $coordination->spreadsheetSource;

        if ($source === null) {
            return redirect()->to($this->home())->with('error', __('sheet_sources.errors.configure_first'));
        }

        try {
            $result = $importer->importUploaded($source, (string) $request->file('file')?->get(), $request->user());
        } catch (SheetInboxException $exception) {
            return redirect()->to($this->home())->with('error', $exception->getMessage());
        }

        return redirect()->to($this->home())->with('notice', __('sheet_sources.uploaded', $result));
    }

    private function home(): string
    {
        return route('inbox-settings.index');
    }
}
