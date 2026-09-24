<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Filtros ya validados del panel de seguimiento. Valor inmutable; NO decide alcance (eso es DashboardScope):
 * `teamId` y `userId` solo ESTRECHAN lo que el usuario ya puede ver.
 *
 * El periodo son dos fechas de calendario en la zona horaria de negocio (America/Cancun), ambas inclusivas.
 * Los timestamps se guardan en la zona de la aplicación (UTC), así que los límites del periodo se convierten
 * a esa zona como un intervalo semiabierto [inicio, fin) para comparar contra `created_at`/`completed_at`.
 */
final readonly class DashboardFilters
{
    public function __construct(
        public string $from,
        public string $to,
        public ?int $teamId = null,
        public ?int $userId = null,
        public ?int $categoryId = null,
    ) {}

    /**
     * Periodo por defecto: los últimos N días terminando hoy (hora de negocio), ambos inclusivos.
     *
     * @param  array{from?: ?string, to?: ?string, team_id?: ?int, user_id?: ?int, category_id?: ?int}  $data  Datos ya validados.
     */
    public static function fromValidated(array $data): self
    {
        $today = LocalTime::now()->startOfDay();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], self::displayTimezone())->startOfDay() : $today;
        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'], self::displayTimezone())->startOfDay()
            : $to->subDays(max((int) config('tickets.dashboard.default_period_days') - 1, 0));

        return new self(
            from: $from->toDateString(),
            to: $to->toDateString(),
            teamId: isset($data['team_id']) ? (int) $data['team_id'] : null,
            userId: isset($data['user_id']) ? (int) $data['user_id'] : null,
            categoryId: isset($data['category_id']) ? (int) $data['category_id'] : null,
        );
    }

    /**
     * Inicio del periodo (inclusive) en la zona de almacenamiento, formato de columna de la BD.
     */
    public function startsAt(): string
    {
        return LocalTime::storageBoundary($this->from);
    }

    /**
     * Fin del periodo (EXCLUSIVO): el inicio del día siguiente a `to`.
     */
    public function endsBefore(): string
    {
        return LocalTime::storageBoundary($this->to, 1);
    }

    /**
     * Días del periodo, ambos extremos inclusivos.
     */
    public function days(): int
    {
        return (int) round(CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to), true)) + 1;
    }

    /**
     * Valores que identifican el resultado en cache (junto con el usuario y su alcance).
     *
     * @return array<string, int|string|null>
     */
    public function cacheKey(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'team' => $this->teamId,
            'user' => $this->userId,
            'category' => $this->categoryId,
        ];
    }

    private static function displayTimezone(): string
    {
        return (string) config('app.display_timezone');
    }
}
