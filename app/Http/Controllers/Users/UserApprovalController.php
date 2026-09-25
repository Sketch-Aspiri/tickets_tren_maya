<?php

declare(strict_types=1);

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\ApproveUserRequest;
use App\Http\Requests\Users\RejectUserRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;

class UserApprovalController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function approve(ApproveUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('approve', $user);

        $this->users->approve($request->user(), $user, $request->selectedRole(), $request->selectedTeamIds());

        return redirect()->route('users.show', $user)->with('status', 'user-approved');
    }

    public function reject(RejectUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('reject', $user);

        $this->users->reject($request->user(), $user, $request->validated('reason'));

        return redirect()->route('users.index')->with('status', 'user-rejected');
    }
}
