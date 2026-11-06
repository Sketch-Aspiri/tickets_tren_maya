<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PurgeOverdueRequest;
use App\Services\OverduePurgeService;
use Illuminate\Http\RedirectResponse;

/**
 * Botón "Eliminar vencidos": solo jefe de zona, coordinador y administrador (habilidad `purge-overdue`).
 */
class OverduePurgeController extends Controller
{
    public function __construct(private readonly OverduePurgeService $purger) {}

    public function store(PurgeOverdueRequest $request): RedirectResponse
    {
        $this->purger->purge($request->user());

        return redirect()->back(fallback: route('tickets.index'))->with('status', 'overdue-purged');
    }
}
