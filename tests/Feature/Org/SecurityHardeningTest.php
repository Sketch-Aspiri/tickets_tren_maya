<?php

declare(strict_types=1);

namespace Tests\Feature\Org;

use App\Models\Coordination;
use App\Models\Team;
use App\Models\User;
use App\Rules\PublicMailHost;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

class SecurityHardeningTest extends DatabaseTestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function addresses(): array
    {
        return [
            'publica' => ['93.184.216.34', true],
            'cgnat tailscale' => ['100.64.1.2', false],
            'benchmarking' => ['198.18.0.1', false],
            'documentacion' => ['203.0.113.9', false],
            'privada' => ['10.0.0.1', false],
            'loopback' => ['127.0.0.1', false],
            'v6 loopback' => ['::1', false],
            'v4 mapeada a loopback' => ['::ffff:127.0.0.1', false],
            'v4 mapeada a cgnat' => ['::ffff:100.64.0.1', false],
            'v4 mapeada publica' => ['::ffff:93.184.216.34', true],
            '6to4 con privada' => ['2002:0a00:0001::1', false],
        ];
    }

    #[DataProvider('addresses')]
    public function test_public_address_detection(string $address, bool $expected): void
    {
        $this->assertSame($expected, PublicMailHost::isPublic($address), $address);
    }

    public function test_a_boss_only_changes_team_memberships_inside_their_scope(): void
    {
        $coordA = Coordination::factory()->create();
        $coordB = Coordination::factory()->create();
        $teamA = Team::factory()->create(['coordination_id' => $coordA->id]);
        $teamA2 = Team::factory()->create(['coordination_id' => $coordA->id]);
        $teamB = Team::factory()->create(['coordination_id' => $coordB->id]);
        $boss = User::factory()->jefeOf($coordA)->create();
        $person = User::factory()->empleado()->create();
        $person->teams()->sync([$teamA->id, $teamB->id]);

        $this->signIn($boss)->put("/users/{$person->id}", ['role' => 'empleado', 'team_ids' => [$teamA2->id]])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$teamA2->id, $teamB->id], $person->fresh()->teamIds());
    }

    public function test_an_active_user_placed_elsewhere_is_no_longer_reachable_by_the_requested_coordination(): void
    {
        $coordA = Coordination::factory()->create();
        $coordB = Coordination::factory()->create();
        $teamB = Team::factory()->create(['coordination_id' => $coordB->id]);
        $bossA = User::factory()->jefeOf($coordA)->create();
        $person = User::factory()->empleado()->create();
        $person->teams()->sync([$teamB->id]);
        $person->forceFill(['requested_coordination_id' => $coordA->id])->save();

        $this->signIn($bossA)->get("/users/{$person->id}")->assertNotFound();
    }
}
