<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Subtask;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

/**
 * Autenticación, estado de cuenta y 2FA sobre TODAS las rutas de actividades y sus adjuntos.
 */
class ActivityAccessMatrixTest extends DatabaseTestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'activities index' => ['get', '/activities'],
            'activities create' => ['get', '/activities/create'],
            'activities store' => ['post', '/activities'],
            'activities show' => ['get', '/activities/{activity}'],
            'activities edit' => ['get', '/activities/{activity}/edit'],
            'activities update' => ['put', '/activities/{activity}'],
            'activities destroy' => ['delete', '/activities/{activity}'],
            'activities transition' => ['post', '/activities/{activity}/transition'],
            'activities assign' => ['put', '/activities/{activity}/assignments'],
            'activities comment' => ['post', '/activities/{activity}/comments'],
            'activities attach' => ['post', '/activities/{activity}/attachments'],
            'subtasks store' => ['post', '/activities/{activity}/subtasks'],
            'subtasks update' => ['put', '/activities/{activity}/subtasks/{subtask}'],
            'subtasks done' => ['post', '/activities/{activity}/subtasks/{subtask}/done'],
            'subtasks destroy' => ['delete', '/activities/{activity}/subtasks/{subtask}'],
            'attachment download' => ['get', '/attachments/{attachment}'],
            'attachment destroy' => ['delete', '/attachments/{attachment}'],
        ];
    }

    private function resolve(string $uri): string
    {
        $activity = Activity::factory()->create();
        $subtask = Subtask::factory()->for($activity)->create();
        $attachment = Attachment::factory()->for($activity, 'attachable')->create();

        return str_replace(
            ['{activity}', '{subtask}', '{attachment}'],
            [(string) $activity->id, (string) $subtask->id, (string) $attachment->id],
            $uri,
        );
    }

    private function send(?User $actor, string $method, string $uri, bool $verified = true): TestResponse
    {
        $uri = $this->resolve($uri);
        $client = $actor === null ? $this : ($verified ? $this->signIn($actor) : $this->actingAs($actor));

        return $method === 'get' ? $client->get($uri) : $client->{$method}($uri, []);
    }

    #[DataProvider('endpoints')]
    public function test_guest_is_redirected_to_login(string $method, string $uri): void
    {
        $this->send(null, $method, $uri)->assertRedirect('/login');
    }

    #[DataProvider('endpoints')]
    public function test_pending_user_is_sent_to_the_status_screen(string $method, string $uri): void
    {
        $this->send(User::factory()->pending()->create(), $method, $uri, verified: false)->assertRedirect(route('account.status'));
    }

    #[DataProvider('endpoints')]
    public function test_inactive_user_is_sent_to_the_status_screen(string $method, string $uri): void
    {
        $this->send(User::factory()->empleado()->inactive()->create(), $method, $uri)->assertRedirect(route('account.status'));
        $this->send(User::factory()->jefe()->inactive()->create(), $method, $uri)->assertRedirect(route('account.status'));
    }

    #[DataProvider('endpoints')]
    public function test_active_user_without_a_role_is_sent_to_the_status_screen(string $method, string $uri): void
    {
        $this->send(User::factory()->active()->create(), $method, $uri)->assertRedirect(route('account.status'));
    }

    #[DataProvider('endpoints')]
    public function test_jefe_without_two_factor_configured_is_forced_to_set_it_up(string $method, string $uri): void
    {
        $jefe = User::factory()->active()->withRole(UserRole::JefeZona)->create();

        $this->send($jefe, $method, $uri, verified: false)->assertRedirect(route('two-factor.setup'));
    }

    #[DataProvider('endpoints')]
    public function test_coordinator_without_two_factor_configured_is_forced_to_set_it_up(string $method, string $uri): void
    {
        $coordinator = User::factory()->active()->withRole(UserRole::Coordinador)->create(['team_id' => Team::factory()->create()->id]);

        $this->send($coordinator, $method, $uri, verified: false)->assertRedirect(route('two-factor.setup'));
    }

    #[DataProvider('endpoints')]
    public function test_jefe_with_two_factor_but_no_challenge_passed_is_sent_to_the_challenge(string $method, string $uri): void
    {
        $this->send(User::factory()->jefe()->create(), $method, $uri, verified: false)->assertRedirect(route('two-factor.challenge'));
    }

    #[DataProvider('endpoints')]
    public function test_json_requests_from_unverified_users_get_403_not_a_redirect(string $method, string $uri): void
    {
        $uri = $this->resolve($uri);
        $this->actingAs(User::factory()->jefe()->create());

        $this->json(strtoupper($method), $uri)->assertForbidden();
    }
}
