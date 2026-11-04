<?php

declare(strict_types=1);

namespace Tests\Feature\Org;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La migracion que agrega `coordination_id` debe conservar los datos existentes: crea una coordinacion por
 * defecto, le asigna todos los equipos y liga a los jefes de zona activos (nadie pierde visibilidad).
 */
class CoordinationBackfillMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private function rollbackCoordinationColumns(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 4, '--force' => true]);
        $this->assertFalse(Schema::hasColumn('teams', 'coordination_id'));
    }

    private function insertUser(string $email, string $status): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => $email,
            'email' => $email,
            'password' => 'x',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_existing_teams_and_active_jefes_are_backfilled_into_a_default_coordination(): void
    {
        $this->rollbackCoordinationColumns();

        $roleId = (int) DB::table('roles')->insertGetId(['name' => 'jefe_zona', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        $activeJefe = $this->insertUser('activo@test.dev', 'active');
        $inactiveJefe = $this->insertUser('inactivo@test.dev', 'inactive');
        $pending = $this->insertUser('pendiente@test.dev', 'pending');
        foreach ([$activeJefe, $inactiveJefe] as $id) {
            DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => 'App\Models\User', 'model_id' => $id]);
        }
        DB::table('teams')->insert([
            ['name' => 'Alfa', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Beta', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(1, DB::table('stations')->count());
        $this->assertSame(1, DB::table('coordinations')->count());
        $coordinationId = (int) DB::table('coordinations')->value('id');
        $this->assertSame(2, DB::table('teams')->where('coordination_id', $coordinationId)->count());
        // Las solicitudes pendientes llegan al jefe de la coordinacion por defecto.
        $this->assertSame($coordinationId, (int) DB::table('users')->where('id', $pending)->value('requested_coordination_id'));
        $this->assertSame([$activeJefe], DB::table('coordination_user')->where('coordination_id', $coordinationId)->pluck('user_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_an_empty_database_gets_no_default_coordination(): void
    {
        $this->assertSame(0, DB::table('stations')->count());
        $this->assertSame(0, DB::table('coordinations')->count());
    }

    public function test_migration_is_reversible_and_restores_global_unique_names(): void
    {
        $this->rollbackCoordinationColumns();

        $this->assertFalse(Schema::hasColumn('categories', 'coordination_id'));
        $this->assertFalse(Schema::hasColumn('users', 'requested_coordination_id'));

        Artisan::call('migrate', ['--force' => true]);

        $this->assertTrue(Schema::hasColumn('teams', 'coordination_id'));
        $this->assertTrue(Schema::hasColumn('categories', 'coordination_id'));
        $this->assertTrue(Schema::hasColumn('users', 'requested_coordination_id'));
    }
}
