<?php

declare(strict_types=1);

namespace Tests\Feature\Org;

use App\Models\Coordination;
use App\Models\Station;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

class OrganizationAccessMatrixTest extends DatabaseTestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function routes(): array
    {
        return [
            'stations index' => ['get', '/stations'],
            'stations create' => ['get', '/stations/create'],
            'stations store' => ['post', '/stations'],
            'stations edit' => ['get', '/stations/{station}/edit'],
            'stations update' => ['put', '/stations/{station}'],
            'stations destroy' => ['delete', '/stations/{station}'],
            'coordinations index' => ['get', '/coordinations'],
            'coordinations create' => ['get', '/coordinations/create'],
            'coordinations store' => ['post', '/coordinations'],
            'coordinations edit' => ['get', '/coordinations/{coordination}/edit'],
            'coordinations update' => ['put', '/coordinations/{coordination}'],
            'coordinations destroy' => ['delete', '/coordinations/{coordination}'],
        ];
    }

    #[DataProvider('routes')]
    public function test_only_the_administrator_reaches_organization_routes(string $method, string $uri): void
    {
        $station = Station::factory()->create();
        $coordination = Coordination::factory()->create();
        $uri = str_replace(['{station}', '{coordination}'], [(string) $station->id, (string) $coordination->id], $uri);

        foreach ([User::factory()->jefe()->create(), User::factory()->coordinador()->create(), User::factory()->empleado()->create()] as $user) {
            $this->signIn($user)->{$method}($uri)->assertForbidden();
        }

        $this->assertModelExists($station);
        $this->assertModelExists($coordination);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/stations')->assertRedirect('/login');
        $this->get('/coordinations')->assertRedirect('/login');
    }

    public function test_administrator_sees_the_organization_menu_and_the_jefe_does_not(): void
    {
        $this->signIn(User::factory()->administrador()->create())->get('/dashboard')
            ->assertSee(route('stations.index'))->assertSee(route('coordinations.index'));
        $this->signIn(User::factory()->jefe()->create())->get('/dashboard')
            ->assertDontSee(route('stations.index'))->assertDontSee(route('coordinations.index'));
    }
}
