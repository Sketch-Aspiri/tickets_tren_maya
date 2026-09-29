<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatUnreadController extends Controller
{
    public function __invoke(Request $request, ChatService $chat): JsonResponse
    {
        $this->authorize('viewAny', Conversation::class);

        return response()->json([
            'success' => true,
            'data' => ['unread' => $chat->unreadTotal($request->user())],
            'error' => null,
            'meta' => null,
        ])->header('Cache-Control', 'private, no-store');
    }
}
