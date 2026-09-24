<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use Carbon\CarbonImmutable;

/**
 * Convierte el resultado de DashboardMetricsService en la configuración (datos planos) de cada gráfica. La
 * vista la serializa a JSON en un atributo `data-*` y `resources/js/charts.js` la dibuja con Chart.js: nunca
 * viaja código ni expresiones, solo tipo, etiquetas y números. Las etiquetas ya vienen traducidas.
 */
final class TrackingCharts
{
    /**
     * @param  array<string, mixed>  $report
     * @return array<string, array<string, mixed>>
     */
    public static function build(array $report): array
    {
        return [
            'trend' => self::trend($report['trend']),
            'status' => self::distribution($report['created_distribution']['by_status'], 'status', 'enums.ticket_status.'),
            'priority' => self::distribution($report['created_distribution']['by_priority'], 'priority', 'enums.priority.'),
        ];
    }

    /**
     * @param  array{granularity: string, points: list<array{label: string, created: int, completed: int}>}  $trend
     * @return array<string, mixed>
     */
    private static function trend(array $trend): array
    {
        $points = $trend['points'];

        return [
            'type' => 'line',
            'palette' => 'series',
            'labels' => array_map(fn (array $point): string => CarbonImmutable::parse($point['label'])->format('d/m'), $points),
            'datasets' => [
                ['label' => __('tracking.charts.created'), 'data' => array_column($points, 'created')],
                ['label' => __('tracking.charts.completed'), 'data' => array_column($points, 'completed')],
            ],
        ];
    }

    /**
     * @param  list<array<string, int|string>>  $rows
     * @return array<string, mixed>
     */
    private static function distribution(array $rows, string $palette, string $langPrefix): array
    {
        return [
            'type' => $palette === 'status' ? 'doughnut' : 'bar',
            'palette' => $palette,
            'keys' => array_column($rows, 'key'),
            'labels' => array_map(fn (array $row): string => __($langPrefix.$row['key']), $rows),
            'datasets' => [['label' => __('tracking.charts.total'), 'data' => array_column($rows, 'total')]],
        ];
    }
}
