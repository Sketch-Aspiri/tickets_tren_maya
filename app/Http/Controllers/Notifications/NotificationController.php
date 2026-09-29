<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Services\ChatService;
use App\Services\NotificationCenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Notificaciones del propio usuario. Todo se resuelve por `$user->notifications()`: una notificacion ajena
 * responde 404 (mismo criterio que el resto del sistema), sin exponer que existe.
 */
class NotificationController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly NotificationCenter $center,
        private readonly ChatService $chat,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('notifications.index', [
            'unreadChats' => $this->chat->listFor($user)->filter(fn ($conversation): bool => $conversation->unread_count > 0)->values(),
            'notifications' => $user->notifications()->paginate(self::PER_PAGE),
            'center' => $this->center,
        ]);
    }

    /**
     * Marca como leido y lleva al destino.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->findOrFail($notification);
        $item->markAsRead();

        return redirect($this->center->targetUrl($item));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return redirect()->route('notifications.index')->with('status', 'notifications-read');
    }

    public function summary(Request $request): JsonResponse
    {
        $summary = $this->center->summary($request->user());

        return response()->json([
            'success' => true,
            'data' => ['unread' => $summary['total'], 'chat' => $summary['chat'], 'alerts' => $summary['alerts']],
            'error' => null,
            'meta' => null,
        ])->header('Cache-Control', 'private, no-store');
    }
}
