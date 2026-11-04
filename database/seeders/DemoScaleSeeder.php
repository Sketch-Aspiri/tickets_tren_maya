<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Coordination;
use App\Models\Station;
use App\Services\FolioGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Datos de VOLUMEN para probar rendimiento y aislamiento (solo local/testing, nunca por defecto):
 * 3 estaciones, 5 coordinaciones, 15 equipos, ~100 usuarios y varios miles de tickets, con inserciones por lotes
 * (no pasa por los Services: no genera historial ni bitacora, a proposito). Idempotente por el prefijo `[Escala]`.
 *
 *   php artisan db:seed --class=DemoScaleSeeder
 *
 * Contrasena de las cuentas: DEMO_USER_PASSWORD (o una aleatoria que se imprime una vez).
 */
class DemoScaleSeeder extends Seeder
{
    public const TICKETS = 4000;

    private const PREFIX = '[Escala] ';

    /** estacion => [codigo => nombre de coordinacion] */
    private const LAYOUT = [
        'Estación Mérida' => ['ESC-1' => 'Operaciones Mérida', 'ESC-2' => 'Sistemas Mérida'],
        'Estación Cancún' => ['ESC-3' => 'Operaciones Cancún', 'ESC-4' => 'Mantenimiento Cancún'],
        'Estación Campeche' => ['ESC-5' => 'Operaciones Campeche'],
    ];

    private const TEAMS_PER_COORDINATION = 3;

    private const EMPLOYEES_PER_TEAM = 6;

    public function run(): void
    {
        if (! app()->environment(DemoDataSeeder::ALLOWED_ENVIRONMENTS)) {
            $this->command?->warn('DemoScaleSeeder solo corre en local.');

            return;
        }

        if (DB::table('tickets')->where('title', 'like', self::PREFIX.'%')->exists()) {
            $this->command?->info('Los datos de escala ya existen.');

            return;
        }

        $password = (string) (config('tickets.demo_password') ?? bin2hex(random_bytes(8)).'Aa1');
        $hash = Hash::make($password);

        $teamIds = [];
        $userIdsByTeam = [];
        $now = now();

        DB::transaction(function () use (&$teamIds, &$userIdsByTeam, $hash, $now): void {
            foreach (self::LAYOUT as $stationName => $coordinations) {
                $station = Station::query()->firstOrCreate(['name' => $stationName], ['active' => true]);

                foreach ($coordinations as $code => $coordinationName) {
                    $coordination = Coordination::query()->firstOrCreate(
                        ['code' => $code],
                        ['station_id' => $station->id, 'name' => $coordinationName, 'active' => true],
                    );

                    $boss = $this->insertUser("Jefe {$coordinationName}", strtolower($code).'.jefe@escala.test', $hash, UserRole::JefeZona, $now);
                    DB::table('coordination_user')->insertOrIgnore(['coordination_id' => $coordination->id, 'user_id' => $boss, 'created_at' => $now, 'updated_at' => $now]);

                    foreach (range(1, self::TEAMS_PER_COORDINATION) as $n) {
                        $teamId = (int) DB::table('teams')->insertGetId([
                            'coordination_id' => $coordination->id,
                            'name' => "Equipo {$n} {$coordinationName}",
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $teamIds[] = $teamId;

                        $coordinator = $this->insertUser("Coordinador {$n} {$coordinationName}", strtolower($code)."-t{$n}.coord@escala.test", $hash, UserRole::Coordinador, $now);
                        DB::table('team_user')->insert(['team_id' => $teamId, 'user_id' => $coordinator, 'created_at' => $now, 'updated_at' => $now]);
                        DB::table('teams')->where('id', $teamId)->update(['coordinator_id' => $coordinator]);
                        $userIdsByTeam[$teamId][] = $coordinator;

                        foreach (range(1, self::EMPLOYEES_PER_TEAM) as $e) {
                            $employee = $this->insertUser("Empleado {$e} T{$n} {$coordinationName}", strtolower($code)."-t{$n}.emp{$e}@escala.test", $hash, UserRole::Empleado, $now);
                            DB::table('team_user')->insert(['team_id' => $teamId, 'user_id' => $employee, 'created_at' => $now, 'updated_at' => $now]);
                            $userIdsByTeam[$teamId][] = $employee;
                        }
                    }
                }
            }
        });

        $this->seedTickets($teamIds, $userIdsByTeam);

        $this->command?->info('Datos de escala creados (@escala.test). Contrasena: '.$password);
    }

    private function insertUser(string $name, string $email, string $hash, UserRole $role, mixed $now): int
    {
        $id = (int) DB::table('users')->insertGetId([
            'name' => $name,
            'email' => $email,
            'password' => $hash,
            'status' => UserStatus::Active->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleId = Role::findOrCreate($role->value, 'web')->id;
        DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => 'App\Models\User', 'model_id' => $id]);

        return $id;
    }

    /**
     * @param  list<int>  $teamIds
     * @param  array<int, list<int>>  $userIdsByTeam
     */
    private function seedTickets(array $teamIds, array $userIdsByTeam): void
    {
        $statuses = TicketStatus::cases();
        $priorities = Priority::cases();
        $year = (int) now()->year;
        $now = now();

        // Reserva el bloque de folios con una sola actualizacion del contador.
        $folios = app(FolioGenerator::class);
        $first = $folios->next('TM', $year);
        $startNumber = (int) substr($first, strrpos($first, '-') + 1);
        DB::table('folio_sequences')->where('prefix', 'TM')->where('year', $year)->update(['last_number' => $startNumber + self::TICKETS - 1]);

        $rows = [];

        for ($i = 0; $i < self::TICKETS; $i++) {
            $teamId = $teamIds[$i % count($teamIds)];
            $status = $statuses[$i % count($statuses)];
            $createdAt = $now->copy()->subDays($i % 60)->subMinutes($i % 1440);
            $rows[] = [
                'folio' => sprintf('TM-%d-%04d', $year, $startNumber + $i),
                'title' => self::PREFIX.'Ticket '.($i + 1),
                'description' => 'Ticket de volumen para pruebas de rendimiento.',
                'priority' => $priorities[$i % count($priorities)]->value,
                'status' => $status->value,
                'team_id' => $teamId,
                'created_by' => $userIdsByTeam[$teamId][($i % self::EMPLOYEES_PER_TEAM) + 1],
                'source' => 'web',
                'due_date' => $createdAt->copy()->addDays(7)->toDateString(),
                'completed_at' => $status === TicketStatus::Completed ? $createdAt->copy()->addDays(2) : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            if (count($rows) === 500) {
                DB::table('tickets')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('tickets')->insert($rows);
        }
    }
}
