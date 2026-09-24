<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('dashboard', [
            'user' => $user,
            'pendingCount' => $user->can('viewAny', User::class) ? $this->users->pendingCount() : null,
        ]);
    }
}
