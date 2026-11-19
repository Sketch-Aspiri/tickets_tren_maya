<?php

declare(strict_types=1);

namespace Tests\Feature\Ecosystem;

use App\Models\Coordination;
use App\Models\Team;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;

/** Equipos creados en el Dashboard: equipo central + subequipos solo en las estaciones elegidas, idempotente. */
class EcosystemTeamTest extends DatabaseTestCase
{
    private const SECRET = 'secreto-dashboard-a-tickets-de-prueba-0123456789';

    private const PATH = '/interno/equipos';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosistema.puente.secreto_entrada' => self::SECRET,
            'ecosistema.puente.secreto_salida' => 'secreto-tickets-a-dashboard-de-prueba-0123456789',
            'ecosistema.puente.ips' => [],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function signedPost(array $payload)
    {
        $body = json_encode($payload);
        $date = now()->getTimestamp();
        $nonce = Str::random(40);
        $canonical = implode("\n", ['POST', self::PATH, '', 'dashboard', (string) $date, $nonce, hash('sha256', $body)]);

        return $this->call('POST', self::PATH, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PUENTE_SISTEMA' => 'dashboard',
            'HTTP_X_PUENTE_FECHA' => (string) $date,
            'HTTP_X_PUENTE_NONCE' => $nonce,
            'HTTP_X_PUENTE_FIRMA' => hash_hmac('sha256', $canonical, self::SECRET),
        ], $body);
    }

    public function test_creates_the_central_team_and_only_the_chosen_stations(): void
    {
        $coordination = Coordination::factory()->create();
        Team::factory()->create(['coordination_id' => $coordination->id]);

        $payload = ['clave' => 'calidad', 'nombre' => 'Calidad', 'estaciones' => [
            ['codigo' => '25', 'nombre' => 'Tulum'], ['codigo' => '30', 'nombre' => 'Chetumal'],
        ]];

        $this->signedPost($payload)->assertOk()->assertJsonPath('creados', 2);

        $central = Team::query()->whereNull('parent_team_id')->where('area', 'calidad')->firstOrFail();
        $this->assertSame($coordination->id, $central->coordination_id);
        $this->assertSame(['Calidad Chetumal', 'Calidad Tulum'], Team::query()->where('parent_team_id', $central->id)->orderBy('name')->pluck('name')->all());

        // Idempotente, y agregar una estacion solo crea la que falta.
        $this->signedPost($payload)->assertOk()->assertJsonPath('creados', 0);
        $payload['estaciones'][] = ['codigo' => '24', 'nombre' => 'Playa del Carmen'];
        $this->signedPost($payload)->assertOk()->assertJsonPath('creados', 1);
        $this->assertSame(1, Team::query()->where('area', 'calidad')->whereNull('parent_team_id')->count());
    }

    public function test_rejects_an_invalid_key_and_unsigned_requests(): void
    {
        Team::factory()->create();

        $this->signedPost(['clave' => 'Mala Clave', 'nombre' => 'X', 'estaciones' => []])->assertUnprocessable();
        $this->postJson(self::PATH, ['clave' => 'calidad', 'nombre' => 'Calidad', 'estaciones' => []])->assertUnauthorized();
    }
}
