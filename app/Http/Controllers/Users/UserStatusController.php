<?php

declare(strict_types=1);

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\ActivateUserRequest;
use App\Http\Requests\Users\DeactivateUserRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;

class UserStatusController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function activate(ActivateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('activate', $user);

        $this->users->activate($request->user(), $user);

        return redirect()->route('users.show', $user)->with('status', 'user-activated');
    }

    public function deactivate(DeactivateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('deactivate', $user);

        $this->users->deactivate($request->user(), $user, $request->validated('reason'));

        return redirect()->route('users.show', $user)->with('status', 'user-deactivated');
    }
}
