<?php

declare(strict_types=1);

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Models\Coordination;
use App\Services\SheetInbox\MicrosoftPersonalAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * "Mi bandeja": cada jefe o coordinador configura y sincroniza la bandeja de SUS coordinaciones (buzón de correo
 * y hoja de Excel); el administrador ve todas.
 */
class InboxSettingsController extends Controller
{
    public function index(Request $request, MicrosoftPersonalAuth $auth): View
    {
        Gate::authorize('viewInboxSettings', Coordination::class);
        $user = $request->user();

        $coordinations = Coordination::query()
            ->with(['station:id,name', 'mailbox', 'spreadsheetSource'])
            ->when(! $user->seesAllTeams(), fn ($query) => $query->whereKey($user->coordinationIds()))
            ->get()
            ->sortBy(fn (Coordination $coordination): string => mb_strtolower($coordination->station->name.'|'.$coordination->name))
            ->values();

        return view('org.inbox-settings.index', [
            'coordinations' => $coordinations,
            'sheetsConfigured' => $auth->isConfigured(),
        ]);
    }
}
