<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

/**
 * Matriz de permisos (CLAUDE.md seccion 5): gestion de usuarios y equipos solo para jefe de zona.
 */
class UserAccessMatrixTest extends DatabaseTestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'users index' => ['get', '/users'],
            'users show' => ['get', '/users/{target}'],
            'users update' => ['put', '/users/{target}'],
            'users approve' => ['post', '/users/{target}/approve'],
            'users reject' => ['post', '/users/{target}/reject'],
            'users activate' => ['post', '/users/{target}/activate'],
            'users deactivate' => ['post', '/users/{target}/deactivate'],
            'teams index' => ['get', '/teams'],
            'teams create' => ['get', '/teams/create'],
            'teams store' => ['post', '/teams'],
            'teams edit' => ['get', '/teams/{team}/edit'],
            'teams update' => ['put', '/teams/{team}'],
            'teams destroy' => ['delete', '/teams/{team}'],
        ];
    }

    private function requestAs(User $actor, string $method, string $uri, bool $verified = true): TestResponse
    {
        $target = User::factory()->pending()->create();
        $team = Team::factory()->create();
        $uri = str_replace(['{target}', '{team}'], [(string) $target->id, (string) $team->id], $uri);

        $client = $verified ? $this->signIn($actor) : $this->actingAs($actor);

        if ($method === 'get') {
            return $client->get($uri);
        }

        return $client->{$method}($uri, ['role' => 'empleado', 'team_id' => $team->id, 'name' => 'Equipo X']);
    }

    #[DataProvider('endpoints')]
    public function test_coordinador_gets_403(string $method, string $uri): void
    {
        $this->requestAs(User::factory()->coordinador()->create(), $method, $uri)->assertForbidden();
    }

    #[DataProvider('endpoints')]
    public function test_empleado_gets_403(string $method, string $uri): void
    {
        $this->requestAs(User::factory()->empleado()->create(), $method, $uri)->assertForbidden();
    }

    #[DataProvider('endpoints')]
    public function test_pending_user_is_sent_to_the_status_screen(string $method, string $uri): void
    {
        $this->requestAs(User::factory()->pending()->create(), $method, $uri, verified: false)
            ->assertRedirect(route('account.status'));
    }

    #[DataProvider('endpoints')]
    public function test_inactive_jefe_is_sent_to_the_status_screen(string $method, string $uri): void
    {
        $this->requestAs(User::factory()->jefe()->inactive()->create(), $method, $uri)
            ->assertRedirect(route('account.status'));
    }

    #[DataProvider('endpoints')]
    public function test_guest_is_redirected_to_login(string $method, string $uri): void
    {
        $target = User::factory()->pending()->create();
        $team = Team::factory()->create();
        $uri = str_replace(['{target}', '{team}'], [(string) $target->id, (string) $team->id], $uri);

        $this->{$method}($uri)->assertRedirect('/login');
    }

    #[DataProvider('endpoints')]
    public function test_jefe_without_completed_two_factor_is_sent_to_the_challenge(string $method, string $uri): void
    {
        $this->requestAs(User::factory()->jefe()->create(), $method, $uri, verified: false)
            ->assertRedirect(route('two-factor.challenge'));
    }

    public function test_jefe_can_open_the_read_screens(): void
    {
        $jefe = User::factory()->jefe()->create();
        $target = User::factory()->pending()->create();
        $team = Team::factory()->create();

        $this->signIn($jefe)->get('/users')->assertOk();
        $this->signIn($jefe)->get("/users/{$target->id}")->assertOk()->assertSee($target->email);
        $this->signIn($jefe)->get('/teams')->assertOk();
        $this->signIn($jefe)->get('/teams/create')->assertOk();
        $this->signIn($jefe)->get("/teams/{$team->id}/edit")->assertOk();
    }

    public function test_navigation_only_shows_management_links_to_the_jefe(): void
    {
        $jefe = User::factory()->jefe()->create();
        $empleado = User::factory()->empleado()->create();

        $this->signIn($jefe)->get('/dashboard')->assertSee(route('users.index'))->assertSee(route('teams.index'));
        $this->signIn($empleado)->get('/dashboard')->assertDontSee(route('users.index'))->assertDontSee(route('teams.index'));
    }

    public function test_idor_a_coordinador_cannot_read_or_change_any_user_by_changing_the_id(): void
    {
        $coordinador = User::factory()->coordinador()->create();
        $victim = User::factory()->empleado()->create(['team_id' => $coordinador->team_id]);

        $this->signIn($coordinador)->get("/users/{$victim->id}")->assertForbidden();
        $this->signIn($coordinador)->put("/users/{$victim->id}", ['role' => 'jefe_zona'])->assertForbidden();
        $this->signIn($coordinador)->post("/users/{$victim->id}/deactivate")->assertForbidden();

        $fresh = $victim->fresh();
        $this->assertTrue($fresh->isActive());
        $this->assertSame('empleado', $fresh->roleEnum()->value);
    }

    public function test_privilege_escalation_by_self_approval_is_blocked(): void
    {
        $pending = User::factory()->pending()->create();
        $team = Team::factory()->create();

        $this->actingAs($pending)->post("/users/{$pending->id}/approve", ['role' => 'jefe_zona', 'team_id' => $team->id])
            ->assertRedirect(route('account.status'));

        $this->assertTrue($pending->fresh()->isPending());
    }

    public function test_missing_user_returns_404_for_the_jefe(): void
    {
        $jefe = User::factory()->jefe()->create();

        $this->signIn($jefe)->get('/users/999999')->assertNotFound();
    }
}
