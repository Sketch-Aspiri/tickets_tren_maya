<?php

declare(strict_types=1);

namespace Tests\Feature\Emails;

use App\Enums\IncomingEmailStatus;
use App\Models\Coordination;
use App\Models\IncomingEmail;
use App\Models\Station;
use App\Models\Team;
use App\Models\User;
use Tests\DatabaseTestCase;

/**
 * Las bandejas de "Correos entrantes" se agrupan por estación/edificio y se pueden filtrar por estación o por
 * coordinación (una coordinación elegida manda sobre la estación).
 */
class IncomingEmailsStationGroupingTest extends DatabaseTestCase
{
    private Station $zonal;

    private Station $limones;

    private Coordination $principal;

    private Coordination $soporte;

    private Coordination $cggif;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zonal = Station::factory()->create(['name' => 'Edificio Zonal']);
        $this->limones = Station::factory()->create(['name' => 'Limones Chacchoben']);
        $this->principal = Coordination::factory()->create(['station_id' => $this->zonal->id, 'name' => 'Coordinación principal']);
        $this->soporte = Coordination::factory()->create(['station_id' => $this->zonal->id, 'name' => 'Soporte del Sistema']);
        $this->cggif = Coordination::factory()->create(['station_id' => $this->limones->id, 'name' => 'Infraestructura Ferroviaria']);

        $this->mail($this->principal, 'Oficio principal 1');
        $this->mail($this->principal, 'Oficio principal 2');
        $this->mail($this->soporte, 'Falla de soporte');
        $this->mail($this->cggif, 'Asunto de Limones');
    }

    private function mail(Coordination $coordination, string $subject): void
    {
        $email = new IncomingEmail([
            'coordination_id' => $coordination->id,
            'message_id' => 'm-'.uniqid(),
            'from_email' => 'x@example.com',
            'from_name' => 'X',
            'subject' => $subject,
            'body' => 'cuerpo',
            'received_at' => now(),
        ]);
        $email->forceFill(['status' => IncomingEmailStatus::PendingReview])->save();
    }

    public function test_mailboxes_are_grouped_by_station_with_their_pending_totals(): void
    {
        $html = $this->signIn(User::factory()->administrador()->create())->get(route('incoming-emails.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Edificio Zonal', $html);
        $this->assertStringContainsString('Limones Chacchoben', $html);
        // Total de la estación (2 + 1) y de cada coordinación dentro de su grupo.
        $this->assertMatchesRegularExpression('/Edificio Zonal<\/a>\s*<span[^>]*>\(3\)/', $html);
        $this->assertStringContainsString('Coordinación principal (2)', $html);
        $this->assertStringContainsString('Soporte del Sistema (1)', $html);
        $this->assertStringContainsString('Infraestructura Ferroviaria (1)', $html);
    }

    public function test_station_filter_shows_only_the_emails_of_that_station(): void
    {
        $this->signIn(User::factory()->administrador()->create())
            ->get(route('incoming-emails.index', ['station_id' => $this->zonal->id]))
            ->assertOk()
            ->assertSee('Oficio principal 1')
            ->assertSee('Falla de soporte')
            ->assertDontSee('Asunto de Limones');
    }

    public function test_coordination_filter_wins_over_the_station_filter(): void
    {
        $this->signIn(User::factory()->administrador()->create())
            ->get(route('incoming-emails.index', ['station_id' => $this->zonal->id, 'coordination_id' => $this->soporte->id]))
            ->assertOk()
            ->assertSee('Falla de soporte')
            ->assertDontSee('Oficio principal 1');
    }

    public function test_an_unknown_station_is_ignored(): void
    {
        $this->signIn(User::factory()->administrador()->create())
            ->get(route('incoming-emails.index', ['station_id' => 9999]))
            ->assertOk()
            ->assertSee('Oficio principal 1')
            ->assertSee('Asunto de Limones');
    }

    public function test_station_filter_cannot_widen_the_scope_of_a_coordinator(): void
    {
        $team = Team::factory()->create(['coordination_id' => $this->cggif->id]);
        $coordinator = User::factory()->coordinador()->inTeams($team)->create(['team_id' => $team->id]);

        // Pide la estación ajena (Edificio Zonal): no la ve, así que el filtro se ignora y solo hay lo suyo.
        $this->signIn($coordinator)
            ->get(route('incoming-emails.index', ['station_id' => $this->zonal->id]))
            ->assertOk()
            ->assertDontSee('Oficio principal 1')
            ->assertDontSee('Falla de soporte');
    }
}
