<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\DatabaseTestCase;

class UserIndexTest extends DatabaseTestCase
{
    private function jefe(): User
    {
        return User::factory()->jefe()->create(['name' => 'Jefe Principal']);
    }

    /**
     * @return list<string>
     */
    private function listedNames(TestResponse $response): array
    {
        return $response->viewData('users')->getCollection()->pluck('name')->all();
    }

    public function test_index_is_paginated(): void
    {
        $jefe = $this->jefe();
        User::factory()->count(20)->pending()->create();

        $response = $this->signIn($jefe)->get('/users')->assertOk();

        $paginator = $response->viewData('users');
        $this->assertSame(21, $paginator->total());
        $this->assertCount((int) config('tickets.users_per_page'), $paginator->items());
        $this->signIn($jefe)->get('/users?page=2')->assertOk();
    }

    public function test_filters_by_status(): void
    {
        $jefe = $this->jefe();
        User::factory()->pending()->create(['name' => 'Persona Pendiente']);
        User::factory()->empleado()->inactive()->create(['name' => 'Persona Inactiva']);

        $names = $this->listedNames($this->signIn($jefe)->get('/users?status=pending'));

        $this->assertSame(['Persona Pendiente'], $names);
    }

    public function test_filters_by_role(): void
    {
        $jefe = $this->jefe();
        User::factory()->coordinador()->create(['name' => 'Coord Uno']);
        User::factory()->empleado()->create(['name' => 'Emp Uno']);

        $names = $this->listedNames($this->signIn($jefe)->get('/users?role=coordinador'));

        $this->assertSame(['Coord Uno'], $names);
    }

    public function test_filters_by_team(): void
    {
        $jefe = $this->jefe();
        $team = Team::factory()->create();
        User::factory()->empleado()->create(['name' => 'En Equipo', 'team_id' => $team->id]);
        User::factory()->empleado()->create(['name' => 'Fuera Equipo']);

        $names = $this->listedNames($this->signIn($jefe)->get('/users?team_id='.$team->id));

        $this->assertSame(['En Equipo'], $names);
    }

    public function test_search_matches_name_or_email(): void
    {
        $jefe = $this->jefe();
        User::factory()->pending()->create(['name' => 'Maria Lopez', 'email' => 'maria@example.com']);
        User::factory()->pending()->create(['name' => 'Pedro Ruiz', 'email' => 'pedro@corp.test']);

        $this->assertSame(['Maria Lopez'], $this->listedNames($this->signIn($jefe)->get('/users?q=lopez')));
        $this->assertSame(['Pedro Ruiz'], $this->listedNames($this->signIn($jefe)->get('/users?q=corp.test')));
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        $jefe = User::factory()->jefe()->create(['name' => 'Jefe Principal', 'email' => 'jefe@example.com']);
        User::factory()->pending()->create(['name' => 'Ana Uno', 'email' => 'ana@example.com']);

        $this->assertSame([], $this->listedNames($this->signIn($jefe)->get('/users?q=%25')));
        $this->assertSame([], $this->listedNames($this->signIn($jefe)->get('/users?q=_')));
    }

    public function test_filters_can_be_combined(): void
    {
        $jefe = $this->jefe();
        $team = Team::factory()->create();
        User::factory()->empleado()->create(['name' => 'Match', 'team_id' => $team->id]);
        User::factory()->empleado()->inactive()->create(['name' => 'Wrong Status', 'team_id' => $team->id]);
        User::factory()->coordinador()->create(['name' => 'Wrong Role', 'team_id' => $team->id]);

        $names = $this->listedNames($this->signIn($jefe)->get("/users?status=active&role=empleado&team_id={$team->id}"));

        $this->assertSame(['Match'], $names);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $jefe = $this->jefe();

        $this->signIn($jefe)->get('/users?status=bogus')->assertInvalid(['status']);
        $this->signIn($jefe)->get('/users?role=root')->assertInvalid(['role']);
        $this->signIn($jefe)->get('/users?team_id=999')->assertInvalid(['team_id']);
        $this->signIn($jefe)->get('/users?q='.str_repeat('a', 101))->assertInvalid(['q']);
    }

    public function test_index_escapes_user_supplied_names(): void
    {
        $jefe = $this->jefe();
        User::factory()->pending()->create(['name' => '<script>alert(1)</script>']);

        $this->signIn($jefe)->get('/users')->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_index_does_not_run_n_plus_one_queries(): void
    {
        $jefe = $this->jefe();
        User::factory()->count(3)->empleado()->create();

        $countQueries = function () use ($jefe): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->signIn($jefe)->get('/users')->assertOk();

            return count(DB::getQueryLog());
        };

        $countQueries(); // calentar cache de permisos
        $few = $countQueries();
        User::factory()->count(12)->empleado()->create();
        $many = $countQueries();

        $this->assertSame($few, $many, "Consultas con 4 usuarios: {$few}; con 16 usuarios: {$many} (N+1).");
    }
}
