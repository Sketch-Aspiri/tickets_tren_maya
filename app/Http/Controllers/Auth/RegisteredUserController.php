<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Coordination;
use App\Models\Station;
use App\Services\UserRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly UserRegistrationService $registration) {}

    public function create(): View
    {
        return view('auth.register', [
            'coordinations' => Coordination::query()
                ->with('station:id,name')
                ->where('active', true)
                ->whereIn('station_id', Station::query()->active()->select('id'))
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * La cuenta queda `pending`, sin rol y sin sesion iniciada, hasta que el jefe de zona la apruebe.
     */
    public function store(RegisterRequest $request): RedirectResponse
    {
        /** @var array{name: string, email: string, password: string, coordination_id: int} $data */
        $data = $request->safe()->only(['name', 'email', 'password', 'coordination_id']);

        $this->registration->register($data);

        return redirect()->route('login')->with('status', __('auth.registered'));
    }
}
