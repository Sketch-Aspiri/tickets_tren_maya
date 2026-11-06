<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SearchChatUsersRequest;
use App\Models\User;
use App\Support\ListingQuery;
use Illuminate\Http\JsonResponse;

/**
 * Buscador de personas para iniciar un chat: activas con rol, sin el propio usuario. Solo id y nombre.
 */
class ChatUserSearchController extends Controller
{
    public function __invoke(SearchChatUsersRequest $request): JsonResponse
    {
        $query = User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('roles')
            ->whereKeyNot($request->user()->getKey());

        $term = trim((string) $request->input('q'));

        if ($term !== '') {
            ListingQuery::search($query, $term, ['users.name']);
        }

        // Solo id, nombre y el nombre de su coordinación (ya público en el registro): con varias coordinaciones dos
        // personas homónimas se distinguen sin exponer correo, equipo ni rol.
        $users = $query->orderBy('name')
            ->limit((int) config('tickets.chat.user_search_limit'))
            ->with(['teams:id,coordination_id', 'teams.coordination:id,name'])
            ->get(['id', 'name'])
            ->map(fn (User $user): array => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'place' => (string) $user->teams->pluck('coordination.name')->filter()->unique()->sort()->implode(', '),
            ])
            ->all();

        return response()->json(['success' => true, 'data' => $users, 'error' => null, 'meta' => null])
            ->header('Cache-Control', 'private, no-store');
    }
}
