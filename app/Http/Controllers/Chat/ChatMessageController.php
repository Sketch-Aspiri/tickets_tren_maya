<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\PollMessagesRequest;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ChatService;
use App\Support\ChatMessageFormatter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * Endpoints JSON internos del chat (sesion + CSRF). Responden con el envoltorio del proyecto; `data.html`
 * son fragmentos Blade ya escapados por el servidor.
 */
class ChatMessageController extends Controller
{
    public function __construct(
        private readonly ChatService $chat,
        private readonly ChatMessageFormatter $formatter,
    ) {}

    public function index(PollMessagesRequest $request, Conversation $conversation): JsonResponse
    {
        $messages = $this->chat->messagesAfter($conversation, $request->integer('after'));

        if ($messages->isNotEmpty()) {
            $this->chat->markRead($request->user(), $conversation, (int) $messages->last()->getKey());
        }

        return $this->respond($messages, $request->user(), 200);
    }

    public function store(StoreChatMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $message = $this->chat->send(
            $request->user(),
            $conversation,
            $request->input('body'),
            $request->file('attachment'),
        );

        return $this->respond(new Collection([$message]), $request->user(), 201);
    }

    /**
     * @param  Collection<int, ChatMessage>  $messages
     */
    private function respond(Collection $messages, User $viewer, int $status): JsonResponse
    {
        $rendered = $this->formatter->render($messages, $viewer);

        $html = $messages->map(fn (ChatMessage $message): string => view('chat.partials.message', [
            'message' => $message,
            'body' => $rendered[$message->getKey()],
            'isMine' => (int) $message->user_id === (int) $viewer->getKey(),
        ])->render())->implode('');

        return response()->json([
            'success' => true,
            'data' => [
                'html' => $html,
                'count' => $messages->count(),
                'last_id' => $messages->isEmpty() ? null : (int) $messages->last()->getKey(),
            ],
            'error' => null,
            'meta' => null,
        ], $status)->header('Cache-Control', 'private, no-store');
    }
}
