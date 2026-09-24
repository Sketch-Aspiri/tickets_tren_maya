<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TicketStatus;
use App\Support\LocalTime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Solo local y pruebas. Reparte en el tiempo (últimas ~3 semanas) las fechas de creación y de cierre de los
 * tickets y actividades demo para que el panel de seguimiento muestre tendencia y tiempos de cierre útiles
 * (los servicios los crean todos "ahora"). Actualiza solo las columnas de fecha, sin eventos de modelo (no
 * genera bitácora) y NO toca plantillas de recurrencia ni sus instancias. Es determinista e idempotente: cada
 * fila recibe siempre el mismo desfase según su posición, así que repetirlo deja el mismo resultado relativo a hoy.
 */
class DemoTrackingSeeder extends Seeder
{
    private const DEMO_PREFIX = '[Demo] ';

    /** Tablas afectadas; las actividades excluyen plantillas e instancias (su fecha de ocurrencia es su fecha). */
    private const TABLES = ['tickets', 'activities'];

    public function run(): void
    {
        if (! app()->environment(DemoDataSeeder::ALLOWED_ENVIRONMENTS)) {
            $this->command?->warn('DemoTrackingSeeder solo corre en local.');

            return;
        }

        foreach (self::TABLES as $table) {
            $this->spread($table);
        }
    }

    private function spread(string $table): void
    {
        $rows = DB::table($table)
            ->where('title', 'like', self::DEMO_PREFIX.'%')
            ->when($table === 'activities', fn ($query) => $query->whereNull('recurrence_rule')->whereNull('parent_activity_id'))
            ->orderBy('id')
            ->get(['id', 'status']);

        $noon = LocalTime::now()->setTime(12, 0)->timezone((string) config('app.timezone'));

        foreach ($rows->values() as $index => $row) {
            $createdAt = $noon->subDays((($index * 5) % 21) + 2);
            $values = ['created_at' => $createdAt->format('Y-m-d H:i:s'), 'updated_at' => $createdAt->format('Y-m-d H:i:s')];

            if ($row->status === TicketStatus::Completed->value) {
                $completedAt = $createdAt->addDays(1 + ($index % 4))->addHours($index % 6);
                $values['completed_at'] = ($completedAt->greaterThan($noon) ? $noon : $completedAt)->format('Y-m-d H:i:s');
            }

            DB::table($table)->where('id', $row->id)->update($values);
        }
    }
}
