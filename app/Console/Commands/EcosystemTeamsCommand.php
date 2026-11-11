<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Command;

/**
 * Ayuda para configurar las actividades del Dashboard (decision 92): lista cada equipo con su id, su
 * coordinacion y su coordinador, que es a quien se asignan las actividades. Solo lectura.
 */
class EcosystemTeamsCommand extends Command
{
    protected $signature = 'ecosystem:teams';

    protected $description = 'Lista los equipos (id, coordinacion, coordinador) para configurar ECOSISTEMA_ACT_EQUIPO_* y ECOSISTEMA_ACT_ESTACIONES';

    public function handle(): int
    {
        $rows = Team::query()
            ->with(['coordination:id,name,station_id', 'coordination.station:id,name', 'coordinator:id,name,status'])
            ->orderBy('coordination_id')
            ->orderBy('name')
            ->get()
            ->map(fn (Team $team): array => [
                $team->getKey(),
                $team->name,
                $team->coordination?->station?->name.' › '.$team->coordination?->name,
                $team->coordinator?->name ?? '(sin coordinador: no recibiría actividades)',
            ]);

        $this->table(['id', 'Equipo', 'Edificio › Coordinación', 'Coordinador'], $rows->all());

        $this->newLine();
        $this->line('Eventos configurados: '.implode(', ', array_keys((array) config('ecosistema.actividades.eventos'))));

        foreach ((array) config('ecosistema.actividades.eventos') as $event => $rules) {
            $this->line(sprintf('  %s → equipo general: %s', $event, ($rules['equipo'] ?? null) === null || $rules['equipo'] === '' ? '(sin configurar: no se crean actividades)' : $rules['equipo']));
        }

        return self::SUCCESS;
    }
}
