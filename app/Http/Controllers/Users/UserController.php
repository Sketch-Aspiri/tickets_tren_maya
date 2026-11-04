<?php

declare(strict_types=1);

namespace App\Http\Controllers\Users;

use App\Enums\PermissionName;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\IndexUsersRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\Coordination;
use App\Models\Team;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(IndexUsersRequest $request): View
    {
        $this->authorize('viewAny', User::class);

        return view('users.index', [
            'users' => $this->users->paginate($request->validated(), null, $request->user()),
            'filters' => $request->safe()->only(['status', 'role', 'team_id', 'q']),
            'teams' => Team::query()->manageableBy($request->user())->orderBy('name')->get(['id', 'name']),
            'statuses' => UserStatus::cases(),
            'roles' => UserRole::cases(),
        ]);
    }

    public function show(Request $request, User $user): View
    {
        $this->authorize('view', $user);

        return view('users.show', [
            'managedUser' => $user->load(['roles:id,name', 'teams:id,name', 'coordinations:id,name']),
            'teams' => Team::query()->manageableBy($request->user())->orderBy('name')->get(['id', 'name']),
            'coordinations' => Coordination::query()->with('station:id,name')->orderBy('name')->get(['id', 'station_id', 'name']),
            'roles' => $this->assignableRoles($request->user()),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $this->users->updateRoleAndTeams($request->user(), $user, $request->selectedRole(), $request->selectedTeamIds(), $request->selectedCoordinationIds());

        return redirect()->route('users.show', $user)->with('status', 'user-updated');
    }

    /**
     * Roles que este usuario puede otorgar: el de administrador solo lo otorga quien tiene `admins.manage`.
     *
     * @return list<UserRole>
     */
    private function assignableRoles(User $actor): array
    {
        $canGrantAdmin = $actor->checkPermissionTo(PermissionName::AdminsManage->value);

        return array_values(array_filter(
            UserRole::cases(),
            fn (UserRole $role): bool => $role !== UserRole::Administrador || $canGrantAdmin,
        ));
    }
}
