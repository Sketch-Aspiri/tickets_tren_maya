<?php

declare(strict_types=1);

namespace Tests\Feature\Org;

use App\Models\Coordination;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DemoScaleSeeder;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\DatabaseTestCase;

/**
 * Volumen: 3 estaciones, 5 coordinaciones, ~100 usuarios y miles de tickets. Comprueba que el alcance por
 * coordinacion se mantiene con volumen y que el numero de consultas de los listados no crece con los datos.
 */
class ScaleSeederTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoScaleSeeder::class);
    }

    public function test_the_scale_seeder_builds_the_expected_organization(): void
    {
        $this->assertSame(3, DB::table('stations')->count());
        $this->assertSame(5, Coordination::query()->count());
        $this->assertSame(15, DB::table('teams')->count());
        $this->assertGreaterThanOrEqual(100, User::query()->count());
        $this->assertSame(DemoScaleSeeder::TICKETS, Ticket::query()->count());
    }

    public function test_the_scale_seeder_is_idempotent(): void
    {
        $this->seed(DemoScaleSeeder::class);

        $this->assertSame(DemoScaleSeeder::TICKETS, Ticket::query()->count());
        $this->assertSame(5, Coordination::query()->count());
    }

    public function test_each_boss_sees_exactly_the_tickets_of_their_own_coordination(): void
    {
        $coordination = Coordination::query()->where('code', 'ESC-3')->firstOrFail();
        $boss = User::query()->where('email', 'esc-3.jefe@escala.test')->firstOrFail();
        $expected = Ticket::query()->whereIn('team_id', DB::table('teams')->select('id')->where('coordination_id', $coordination->id))->count();

        $this->assertGreaterThan(0, $expected);
        $this->assertSame($expected, Ticket::query()->visibleTo($boss)->count());
        $this->assertLessThan(DemoScaleSeeder::TICKETS, Ticket::query()->visibleTo($boss)->count());
    }

    public function test_listing_queries_do_not_grow_with_the_data_and_stay_inside_the_scope(): void
    {
        $boss = User::query()->where('email', 'esc-1.jefe@escala.test')->firstOrFail();
        // Los jefes exigen 2FA: se marca como configurado para poder abrir la pagina.
        $boss->forceFill(['two_factor_secret' => (new Google2FA)->generateSecretKey(), 'two_factor_confirmed_at' => now()])->save();

        $count = function () use ($boss): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->signIn($boss)->get('/tickets')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$queries, $response->viewData('tickets')];
        };

        $count(); // calentamiento: cache de permisos y de configuracion
        [$first, $page] = $count();
        $this->assertLessThanOrEqual(25, $first, 'consultas del listado acotadas');
        $this->assertSame(15, $page->count());

        foreach ($page as $ticket) {
            $this->assertSame('ESC-1', Coordination::query()->whereKey(DB::table('teams')->where('id', $ticket->team_id)->value('coordination_id'))->value('code'));
        }

        // Mas datos no deben meter mas consultas.
        DemoScaleSeederExtra::addTickets(200);
        [$second] = $count();
        $this->assertSame($first, $second);
    }
}

/**
 * Agrega tickets sueltos al primer equipo de la coordinacion ESC-1 para medir que las consultas no crecen.
 */
final class DemoScaleSeederExtra
{
    public static function addTickets(int $amount): void
    {
        $teamId = (int) DB::table('teams')->join('coordinations', 'coordinations.id', '=', 'teams.coordination_id')->where('coordinations.code', 'ESC-1')->value('teams.id');
        $creator = (int) DB::table('team_user')->where('team_id', $teamId)->value('user_id');
        $now = now();
        $rows = [];

        for ($i = 0; $i < $amount; $i++) {
            $rows[] = [
                'folio' => 'XX-9999-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'title' => 'Extra '.$i,
                'description' => 'x',
                'priority' => 'medium',
                'status' => 'pending',
                'team_id' => $teamId,
                'created_by' => $creator,
                'source' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('tickets')->insert($rows);
    }
}
