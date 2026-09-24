<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Services\FolioGenerator;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\BuildsTicketScenario;
use Tests\DatabaseTestCase;

class FolioGeneratorTest extends DatabaseTestCase
{
    use BuildsTicketScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_folios_are_consecutive_and_formatted_per_year(): void
    {
        Carbon::setTestNow('2026-03-10 15:00:00');
        $generator = app(FolioGenerator::class);

        $this->assertSame('TM-2026-0001', $generator->next('TM'));
        $this->assertSame('TM-2026-0002', $generator->next('TM'));
        $this->assertSame('TM-2026-0003', $generator->next('TM'));
    }

    public function test_the_counter_restarts_when_the_year_changes(): void
    {
        $generator = app(FolioGenerator::class);

        Carbon::setTestNow('2026-12-31 12:00:00');
        $this->assertSame('TM-2026-0001', $generator->next('TM'));
        $this->assertSame('TM-2026-0002', $generator->next('TM'));

        Carbon::setTestNow('2027-01-01 12:00:00');
        $this->assertSame('TM-2027-0001', $generator->next('TM'));

        Carbon::setTestNow('2026-12-31 18:00:00');
        $this->assertSame('TM-2026-0003', $generator->next('TM'));
    }

    public function test_the_year_follows_the_business_time_zone_not_utc(): void
    {
        // 2027-01-01 03:00 UTC sigue siendo 31 de diciembre de 2026 en Cancun (UTC-5).
        Carbon::setTestNow('2027-01-01 03:00:00');

        $this->assertSame('TM-2026-0001', app(FolioGenerator::class)->next('TM'));

        // 2027-01-01 06:00 UTC ya es 2027 en Cancun.
        Carbon::setTestNow('2027-01-01 06:00:00');

        $this->assertSame('TM-2027-0001', app(FolioGenerator::class)->next('TM'));
    }

    public function test_each_prefix_has_its_own_counter(): void
    {
        Carbon::setTestNow('2026-05-05 12:00:00');
        $generator = app(FolioGenerator::class);

        $this->assertSame('TM-2026-0001', $generator->next('TM'));
        $this->assertSame('ACT-2026-0001', $generator->next('ACT'));
        $this->assertSame('TM-2026-0002', $generator->next('TM'));
    }

    public function test_numbers_beyond_9999_keep_growing_without_collision(): void
    {
        Carbon::setTestNow('2026-05-05 12:00:00');
        DB::table('folio_sequences')->insert(['prefix' => 'TM', 'year' => 2026, 'last_number' => 9999, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame('TM-2026-10000', app(FolioGenerator::class)->next('TM'));
    }

    public function test_tickets_created_through_the_service_get_unique_consecutive_folios(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $service = app(TicketService::class);

        $folios = [];
        foreach (range(1, 5) as $i) {
            $folios[] = $service->create($this->empA1, ['title' => "T{$i}", 'description' => 'd', 'priority' => 'low'])->folio;
        }

        $this->assertSame(['TM-2026-0001', 'TM-2026-0002', 'TM-2026-0003', 'TM-2026-0004', 'TM-2026-0005'], $folios);
        $this->assertSame(5, Ticket::query()->distinct()->count('folio'));
    }

    public function test_a_failed_creation_rolls_back_the_counter_so_no_gaps_remain(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $service = app(TicketService::class);

        $service->create($this->empA1, ['title' => 'Uno', 'description' => 'd', 'priority' => 'low']);

        try {
            DB::transaction(function () use ($service): void {
                $service->create($this->empA1, ['title' => 'Dos', 'description' => 'd', 'priority' => 'low']);

                throw new RuntimeException('fallo despues de generar el folio');
            });
        } catch (RuntimeException) {
            // esperado
        }

        $next = $service->create($this->empA1, ['title' => 'Tres', 'description' => 'd', 'priority' => 'low']);

        $this->assertSame('TM-2026-0002', $next->folio);
    }

    public function test_the_database_enforces_folio_uniqueness(): void
    {
        $ticket = $this->makeTicket($this->teamA);

        $this->expectException(UniqueConstraintViolationException::class);

        Ticket::factory()->forTeam($this->teamA)->create(['folio' => $ticket->folio]);
    }
}
