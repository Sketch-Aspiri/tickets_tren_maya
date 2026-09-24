<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

/**
 * Autenticacion, estado de cuenta y 2FA sobre TODAS las rutas de tickets, categorias y adjuntos.
 */
class TicketAccessMatrixTest extends DatabaseTestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'tickets index' => ['get', '/tickets'],
            'tickets pending' => ['get', '/tickets/pending'],
            'tickets create' => ['get', '/tickets/create'],
            'tickets store' => ['post', '/tickets'],
            'tickets show' => ['get', '/tickets/{ticket}'],
            'tickets edit' => ['get', '/tickets/{ticket}/edit'],
            'tickets update' => ['put', '/tickets/{ticket}'],
            'tickets destroy' => ['delete', '/tickets/{ticket}'],
            'tickets transition' => ['post', '/tickets/{ticket}/transition'],
            'tickets assign' => ['put', '/tickets/{ticket}/assignments'],
            'tickets unassign' => ['delete', '/tickets/{ticket}/assignments'],
            'tickets take' => ['post', '/tickets/{ticket}/take'],
            'tickets comment' => ['post', '/tickets/{ticket}/comments'],
            'tickets attach' => ['post', '/tickets/{ticket}/attachments'],
            'attachment download' => ['get', '/attachments/{attachment}'],
            'attachment destroy' => ['delete', '/attachments/{attachment}'],
            'categories index' => ['get', '/categories'],
            'categories create' => ['get', '/categories/create'],
            'categories store' => ['post', '/categories'],
            'categories edit' => ['get', '/categories/{category}/edit'],
            'categories update' => ['put', '/categories/{category}'],
            'categories destroy' => ['delete', '/categories/{category}'],
        ];
    }

    private function resolve(string $uri): string
    {
        $ticket = Ticket::factory()->create();
        $attachment = Attachment::factory()->for($ticket, 'attachable')->create();
        $category = Category::factory()->create();

        return str_replace(
            ['{ticket}', '{attachment}', '{category}'],
            [(string) $ticket->id, (string) $attachment->id, (string) $category->id],
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

        $response = $this->json(strtoupper($method), $uri);

        $response->assertForbidden();
    }
}
