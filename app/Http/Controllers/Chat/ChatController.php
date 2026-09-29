<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StartDirectConversationRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ChatService;
use App\Support\ChatMessageFormatter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService $chat,
        private readonly ChatMessageFormatter $formatter,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Conversation::class);

        return $this->render($request->user(), null);
    }

    /**
     * Fragmento HTML de la lista de conversaciones (lo pide el polling de la pagina de chat).
     */
    public function list(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Conversation::class);

        $html = view('chat.partials.conversation-list', [
            'conversations' => $this->chat->listFor($request->user()),
            'activeId' => $request->integer('active') ?: null,
        ])->render();

        return response()->json(['success' => true, 'data' => ['html' => $html], 'error' => null, 'meta' => null])
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Conversation $conversation): View
    {
        $this->authorize('view', $conversation);

        return $this->render($request->user(), $conversation);
    }

    public function startDirect(StartDirectConversationRequest $request): RedirectResponse
    {
        $other = User::query()->findOrFail($request->integer('user_id'));

        $conversation = $this->chat->startDirect($request->user(), $other);

        return redirect()->route('chat.show', $conversation);
    }

    private function render(User $user, ?Conversation $active): View
    {
        $conversations = $this->chat->listFor($user);
        $messages = collect();
        $rendered = [];

        if ($active !== null) {
            $active = $conversations->firstWhere('id', $active->getKey());
            $messages = $this->chat->recent($active);
            $rendered = $this->formatter->render($messages, $user);

            if ($messages->isNotEmpty()) {
                $this->chat->markRead($user, $active, (int) $messages->last()->getKey());
                $active->setAttribute('unread_count', 0);
            }
        }

        return view('chat.index', [
            'conversations' => $conversations,
            'active' => $active,
            'messages' => $messages,
            'rendered' => $rendered,
        ]);
    }
}
