<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Support\SqlExpressions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\DatabaseTestCase;

/**
 * Las expresiones por driver (SQLite en pruebas). La rama MySQL se valida solo por forma (no hay MySQL local).
 */
class SqlExpressionsTest extends DatabaseTestCase
{
    public function test_local_date_shifts_the_stored_utc_timestamp_to_business_time(): void
    {
        DB::table('teams')->insert(['name' => 'Zeta', 'created_at' => '2026-09-20 03:00:00', 'updated_at' => '2026-09-20 12:00:00']);

        $expression = SqlExpressions::localDate('teams.created_at', -300);
        $row = DB::table('teams')->selectRaw("{$expression} as bucket")->first();

        $this->assertSame('2026-09-19', $row->bucket);
    }

    public function test_seconds_between_computes_the_difference_in_the_database(): void
    {
        DB::table('teams')->insert(['name' => 'Zeta', 'created_at' => '2026-09-20 12:00:00', 'updated_at' => '2026-09-22 12:00:30']);

        $expression = SqlExpressions::secondsBetween('teams.created_at', 'teams.updated_at');

        $this->assertSame(172830, (int) DB::table('teams')->selectRaw("{$expression} as seconds")->value('seconds'));
    }

    public function test_display_offset_is_the_business_zone_minus_the_storage_zone(): void
    {
        $this->assertSame(-300, SqlExpressions::displayOffsetMinutes(CarbonImmutable::parse('2026-09-24 12:00:00', 'UTC')));
    }

    public function test_columns_must_be_plain_qualified_identifiers(): void
    {
        foreach (['created_at', 'tickets.created_at; drop table users', "tickets.created_at' or '1'='1", 'a.b.c', 'Tickets.created_at'] as $bad) {
            try {
                SqlExpressions::localDate($bad, 0);
                $this->fail("se acepto la columna: {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
