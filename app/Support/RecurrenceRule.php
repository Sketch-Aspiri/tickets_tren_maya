<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Regla de recurrencia (lo que se guarda en `activities.recurrence_rule`) y su cálculo de ocurrencias.
 *
 * Trabaja SOLO con fechas de calendario (`Y-m-d`), sin hora ni zona: "hoy" en la zona de negocio lo
 * decide quien llama (RecurrenceService con LocalTime), así que el cálculo es puro y determinista.
 *
 * - daily: cada `interval` días desde la fecha de inicio.
 * - weekly: cada `interval` semanas (semana ISO, lunes a domingo, contada desde la semana de inicio) en
 *   `days_of_week` (1 = lunes ... 7 = domingo); sin días, el día de la semana de la fecha de inicio.
 * - monthly: cada `interval` meses desde el mes de inicio, el `day_of_month` (sin él, el día de la fecha
 *   de inicio); si el mes es más corto se usa su ÚLTIMO día (31 → 30/28/29). Cada mes se calcula desde
 *   la regla, no encadenando la fecha anterior, así que no se "arrastra" un 28 de febrero a marzo.
 * - `ends_at` (opcional, inclusive) cierra la serie.
 * Nunca hay ocurrencias anteriores a la fecha de inicio.
 */
final class RecurrenceRule
{
    /** Tope de `interval` (evita reglas absurdas y bucles enormes). */
    public const MAX_INTERVAL = 52;

    /**
     * @param  list<int>  $daysOfWeek  1-7, ordenados y sin repetir
     */
    private function __construct(
        public readonly RecurrenceFrequency $frequency,
        public readonly int $interval,
        public readonly array $daysOfWeek,
        public readonly ?int $dayOfMonth,
        public readonly ?string $endsAt,
    ) {}

    /**
     * Normaliza y valida. Descarta las llaves que no aplican a la frecuencia (días de la semana solo en
     * semanal, día del mes solo en mensual).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $frequency = RecurrenceFrequency::tryFrom((string) ($data['frequency'] ?? ''))
            ?? throw new InvalidArgumentException('Frecuencia de recurrencia inválida.');

        $interval = filter_var($data['interval'] ?? null, FILTER_VALIDATE_INT);

        if ($interval === false || $interval < 1 || $interval > self::MAX_INTERVAL) {
            throw new InvalidArgumentException('Intervalo de recurrencia inválido.');
        }

        return new self(
            $frequency,
            $interval,
            $frequency === RecurrenceFrequency::Weekly ? self::daysOfWeek($data['days_of_week'] ?? []) : [],
            $frequency === RecurrenceFrequency::Monthly ? self::dayOfMonth($data['day_of_month'] ?? null) : null,
            self::endDate($data['ends_at'] ?? null),
        );
    }

    /**
     * @return array{frequency: string, interval: int, days_of_week: list<int>, day_of_month: ?int, ends_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'frequency' => $this->frequency->value,
            'interval' => $this->interval,
            'days_of_week' => $this->daysOfWeek,
            'day_of_month' => $this->dayOfMonth,
            'ends_at' => $this->endsAt,
        ];
    }

    /**
     * Fechas (`Y-m-d`, ascendentes) de la serie que cae en [$from, $to], ambos inclusive.
     *
     * @return list<string>
     */
    public function occurrencesBetween(string $startDate, string $from, string $to): array
    {
        $start = self::date($startDate);
        $windowStart = max($start, self::date($from));
        $windowEnd = self::date($to);

        if ($this->endsAt !== null) {
            $windowEnd = min($windowEnd, self::date($this->endsAt));
        }

        if ($windowStart > $windowEnd) {
            return [];
        }

        $dates = match ($this->frequency) {
            RecurrenceFrequency::Daily => $this->daily($start, $windowStart, $windowEnd),
            RecurrenceFrequency::Weekly => $this->weekly($start, $windowStart, $windowEnd),
            RecurrenceFrequency::Monthly => $this->monthly($start, $windowStart, $windowEnd),
        };

        return array_map(fn (CarbonImmutable $date): string => $date->toDateString(), $dates);
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function daily(CarbonImmutable $start, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $elapsed = (int) $start->diffInDays($from);
        $steps = intdiv($elapsed + $this->interval - 1, $this->interval);
        $dates = [];

        for ($date = $start->addDays($steps * $this->interval); $date <= $to; $date = $date->addDays($this->interval)) {
            $dates[] = $date;
        }

        return $dates;
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function weekly(CarbonImmutable $start, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = $this->daysOfWeek === [] ? [$start->isoWeekday()] : $this->daysOfWeek;
        $firstWeek = $start->startOfWeek();
        $dates = [];

        for ($date = $from; $date <= $to; $date = $date->addDay()) {
            $weeksSinceStart = intdiv((int) $firstWeek->diffInDays($date->startOfWeek()), 7);

            if ($weeksSinceStart % $this->interval === 0 && in_array($date->isoWeekday(), $days, true)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function monthly(CarbonImmutable $start, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $day = $this->dayOfMonth ?? $start->day;
        $firstMonth = $start->startOfMonth();
        $monthsToWindow = max(0, (int) $firstMonth->diffInMonths($from->startOfMonth()));
        $dates = [];

        for ($step = intdiv($monthsToWindow, $this->interval) * $this->interval; ; $step += $this->interval) {
            $month = $firstMonth->addMonthsNoOverflow($step);

            if ($month > $to) {
                break;
            }

            $date = $month->day(min($day, $month->daysInMonth));

            if ($date >= $from && $date <= $to) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * @return list<int>
     */
    private static function daysOfWeek(mixed $days): array
    {
        $normalized = [];

        foreach ((array) $days as $day) {
            $number = filter_var($day, FILTER_VALIDATE_INT);

            if ($number === false || $number < 1 || $number > 7) {
                throw new InvalidArgumentException('Día de la semana inválido (1 a 7).');
            }

            $normalized[$number] = $number;
        }

        ksort($normalized);

        return array_values($normalized);
    }

    private static function dayOfMonth(mixed $day): ?int
    {
        if ($day === null || $day === '') {
            return null;
        }

        $number = filter_var($day, FILTER_VALIDATE_INT);

        if ($number === false || $number < 1 || $number > 31) {
            throw new InvalidArgumentException('Día del mes inválido (1 a 31).');
        }

        return $number;
    }

    private static function endDate(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        return self::date((string) $date)->toDateString();
    }

    private static function date(string $value): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (Throwable) {
            $date = false;
        }

        if ($date === false || $date->toDateString() !== $value) {
            throw new InvalidArgumentException('Fecha inválida, se esperaba AAAA-MM-DD.');
        }

        return $date;
    }
}
