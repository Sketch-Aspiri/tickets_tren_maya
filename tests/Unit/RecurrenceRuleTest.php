<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\RecurrenceFrequency;
use App\Support\RecurrenceRule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Cálculo puro de ocurrencias (solo fechas de calendario, sin zona horaria ni base de datos).
 */
class RecurrenceRuleTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $rule
     * @return list<string>
     */
    private function dates(array $rule, string $start, string $from, string $to): array
    {
        return RecurrenceRule::fromArray($rule)->occurrencesBetween($start, $from, $to);
    }

    public function test_daily_every_day_lists_every_date_in_the_window_inclusive(): void
    {
        $this->assertSame(
            ['2026-09-24', '2026-09-25', '2026-09-26'],
            $this->dates(['frequency' => 'daily', 'interval' => 1], '2026-09-01', '2026-09-24', '2026-09-26'),
        );
    }

    public function test_daily_with_interval_keeps_the_phase_from_the_start_date(): void
    {
        // Inicio el 1 de septiembre, cada 3 días: 1, 4, 7, ... 22, 25, 28.
        $this->assertSame(
            ['2026-09-25', '2026-09-28'],
            $this->dates(['frequency' => 'daily', 'interval' => 3], '2026-09-01', '2026-09-23', '2026-09-29'),
        );
    }

    public function test_nothing_occurs_before_the_start_date(): void
    {
        $this->assertSame(
            ['2026-10-01', '2026-10-02'],
            $this->dates(['frequency' => 'daily', 'interval' => 1], '2026-10-01', '2026-09-20', '2026-10-02'),
        );
    }

    public function test_weekly_on_several_days_uses_iso_days_monday_to_sunday(): void
    {
        // 2026-09-21 es lunes. Lunes(1), miércoles(3) y domingo(7).
        $this->assertSame(
            ['2026-09-21', '2026-09-23', '2026-09-27', '2026-09-28', '2026-09-30'],
            $this->dates(['frequency' => 'weekly', 'interval' => 1, 'days_of_week' => [7, 1, 3]], '2026-09-21', '2026-09-21', '2026-09-30'),
        );
    }

    public function test_weekly_without_days_uses_the_weekday_of_the_start_date(): void
    {
        // 2026-09-23 es miércoles.
        $this->assertSame(
            ['2026-09-23', '2026-09-30', '2026-10-07'],
            $this->dates(['frequency' => 'weekly', 'interval' => 1], '2026-09-23', '2026-09-01', '2026-10-10'),
        );
    }

    public function test_weekly_with_interval_two_skips_alternate_weeks(): void
    {
        // Semanas que empiezan el 21/09 (sí), 28/09 (no), 05/10 (sí).
        $this->assertSame(
            ['2026-09-21', '2026-10-05'],
            $this->dates(['frequency' => 'weekly', 'interval' => 2, 'days_of_week' => [1]], '2026-09-21', '2026-09-21', '2026-10-11'),
        );
    }

    public function test_weekly_days_earlier_in_the_start_week_than_the_start_date_do_not_occur(): void
    {
        // Inicio miércoles 23; el lunes 21 de esa semana es anterior al inicio.
        $this->assertSame(
            ['2026-09-23', '2026-09-28'],
            $this->dates(['frequency' => 'weekly', 'interval' => 1, 'days_of_week' => [1, 3]], '2026-09-23', '2026-09-21', '2026-09-29'),
        );
    }

    public function test_monthly_uses_the_day_of_month(): void
    {
        $this->assertSame(
            ['2026-10-15', '2026-11-15', '2026-12-15'],
            $this->dates(['frequency' => 'monthly', 'interval' => 1, 'day_of_month' => 15], '2026-09-20', '2026-09-01', '2026-12-31'),
        );
    }

    public function test_monthly_day_31_falls_on_the_last_day_of_short_months(): void
    {
        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30'],
            $this->dates(['frequency' => 'monthly', 'interval' => 1, 'day_of_month' => 31], '2026-01-01', '2026-01-01', '2026-06-30'),
        );
    }

    public function test_monthly_day_29_in_february_of_a_leap_year_and_a_common_year(): void
    {
        $common = $this->dates(['frequency' => 'monthly', 'interval' => 1, 'day_of_month' => 29], '2026-01-01', '2026-02-01', '2026-02-28');
        $leap = $this->dates(['frequency' => 'monthly', 'interval' => 1, 'day_of_month' => 29], '2028-01-01', '2028-02-01', '2028-02-29');

        $this->assertSame(['2026-02-28'], $common);
        $this->assertSame(['2028-02-29'], $leap);
    }

    public function test_monthly_does_not_drift_after_a_short_month(): void
    {
        // Inicio el 31 de enero, sin day_of_month: 31, 28 (feb), 31 (mar): no se "arrastra" el 28.
        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31'],
            $this->dates(['frequency' => 'monthly', 'interval' => 1], '2026-01-31', '2026-01-01', '2026-03-31'),
        );
    }

    public function test_monthly_with_interval_three_and_a_year_change(): void
    {
        $this->assertSame(
            ['2026-11-10', '2027-02-10', '2027-05-10'],
            $this->dates(['frequency' => 'monthly', 'interval' => 3, 'day_of_month' => 10], '2026-11-01', '2026-11-01', '2027-06-01'),
        );
    }

    public function test_monthly_first_occurrence_is_skipped_when_its_day_precedes_the_start_date(): void
    {
        $this->assertSame(
            ['2026-10-10'],
            $this->dates(['frequency' => 'monthly', 'interval' => 1, 'day_of_month' => 10], '2026-09-20', '2026-09-01', '2026-10-31'),
        );
    }

    public function test_ends_at_is_inclusive_and_stops_the_series(): void
    {
        $this->assertSame(
            ['2026-09-24', '2026-09-25'],
            $this->dates(['frequency' => 'daily', 'interval' => 1, 'ends_at' => '2026-09-25'], '2026-09-01', '2026-09-24', '2026-10-30'),
        );
    }

    public function test_a_window_entirely_after_ends_at_or_inverted_yields_nothing(): void
    {
        $this->assertSame([], $this->dates(['frequency' => 'daily', 'interval' => 1, 'ends_at' => '2026-09-01'], '2026-08-01', '2026-09-10', '2026-09-20'));
        $this->assertSame([], $this->dates(['frequency' => 'daily', 'interval' => 1], '2026-08-01', '2026-09-20', '2026-09-10'));
    }

    public function test_from_array_normalizes_types_and_drops_keys_that_do_not_apply(): void
    {
        $rule = RecurrenceRule::fromArray([
            'frequency' => 'weekly',
            'interval' => '2',
            'days_of_week' => ['3', '1', '3'],
            'day_of_month' => 15,
            'ends_at' => '2026-12-31',
        ]);

        $this->assertSame([
            'frequency' => 'weekly',
            'interval' => 2,
            'days_of_week' => [1, 3],
            'day_of_month' => null,
            'ends_at' => '2026-12-31',
        ], $rule->toArray());
        $this->assertSame(RecurrenceFrequency::Weekly, $rule->frequency);
    }

    public function test_from_array_rejects_invalid_definitions(): void
    {
        $invalid = [
            'unknown frequency' => ['frequency' => 'hourly', 'interval' => 1],
            'zero interval' => ['frequency' => 'daily', 'interval' => 0],
            'day of week 8' => ['frequency' => 'weekly', 'interval' => 1, 'days_of_week' => [8]],
            'day of month 32' => ['frequency' => 'monthly', 'interval' => 1, 'day_of_month' => 32],
            'bad end date' => ['frequency' => 'daily', 'interval' => 1, 'ends_at' => 'mañana'],
        ];

        foreach ($invalid as $label => $definition) {
            try {
                RecurrenceRule::fromArray($definition);
                $this->fail("debió rechazar: {$label}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
