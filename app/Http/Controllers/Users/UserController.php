<?php

declare(strict_types=1);

namespace App\Http\Controllers\Users;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\IndexUsersRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(IndexUsersRequest $request): View
    {
        $this->authorize('viewAny', User::class);

        return view('users.index', [
            'users' => $this->users->paginate($request->validated()),
            'filters' => $request->safe()->only(['status', 'role', 'team_id', 'q']),
            'teams' => Team::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => UserStatus::cases(),
            'roles' => UserRole::cases(),
        ]);
    }

    public function show(User $user): View
    {
        $this->authorize('view', $user);

        return view('users.show', [
            'managedUser' => $user->load(['roles:id,name', 'team:id,name']),
            'teams' => Team::query()->orderBy('name')->get(['id', 'name']),
            'roles' => UserRole::cases(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $this->users->updateRoleAndTeam($request->user(), $user, $request->selectedRole(), $request->selectedTeamId());

        return redirect()->route('users.show', $user)->with('status', 'user-updated');
    }
}
