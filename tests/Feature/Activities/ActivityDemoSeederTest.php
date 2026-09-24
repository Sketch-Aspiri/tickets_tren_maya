<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Subtask;
use App\Models\Team;
use Database\Seeders\DemoActivitiesSeeder;
use Database\Seeders\DemoDataSeeder;
use Tests\DatabaseTestCase;

/**
 * Datos demo de actividades (solo local/testing): variedad de estados, equipos, avance y una serie recurrente.
 */
class ActivityDemoSeederTest extends DatabaseTestCase
{
    public function test_demo_data_includes_varied_activities_with_a_recurring_series_and_instances(): void
    {
        $this->seed(DemoDataSeeder::class);

        $template = Activity::query()->templates()->firstOrFail();
        $this->assertSame('weekly', $template->recurrence_rule['frequency']);
        $this->assertGreaterThan(0, $template->instances()->count(), 'la serie ya tiene instancias generadas');
        $this->assertSame(TicketStatus::Pending, $template->status);
        $this->assertStringStartsWith('ACT-', $template->folio);

        // Distintos estados y equipos.
        $statuses = Activity::query()->withoutTemplates()->pluck('status')->map(fn (TicketStatus $status): string => $status->value)->unique()->all();
        foreach ([TicketStatus::Pending, TicketStatus::InProgress, TicketStatus::Completed, TicketStatus::Cancelled] as $status) {
            $this->assertContains($status->value, $statuses, "hay actividades {$status->value}");
        }
        $this->assertGreaterThan(1, Activity::query()->distinct()->count('team_id'));

        // Progreso variado: sin subtareas, parcial y completo.
        $percents = Activity::query()->withoutTemplates()->withProgress()->get()->map(fn (Activity $activity): int => $activity->progressPercent())->unique()->values()->all();
        $this->assertContains(0, $percents);
        $this->assertContains(100, $percents);
        $this->assertNotEmpty(array_filter($percents, fn (int $percent): bool => $percent > 0 && $percent < 100));
        $this->assertGreaterThan(0, Subtask::query()->count());

        // Alguna vencida (fecha limite pasada y abierta).
        $this->assertGreaterThan(0, Activity::query()->overdue()->count());
    }

    public function test_demo_activities_are_idempotent(): void
    {
        $this->seed(DemoDataSeeder::class);
        $activities = Activity::query()->count();
        $subtasks = Subtask::query()->count();

        $this->seed(DemoDataSeeder::class);

        $this->assertSame($activities, Activity::query()->count());
        $this->assertSame($subtasks, Subtask::query()->count());
    }

    public function test_demo_activities_only_run_in_local_or_testing_even_when_called_directly(): void
    {
        $this->seed(DemoDataSeeder::class);
        // Primero las instancias (la FK a la plantilla es restrict), luego el resto.
        Activity::query()->whereNotNull('parent_activity_id')->forceDelete();
        Activity::query()->forceDelete();

        $this->app['env'] = 'production';
        (new DemoActivitiesSeeder)->run();
        $this->app['env'] = 'testing';

        $this->assertSame(0, Activity::query()->count());
        $this->assertGreaterThan(0, Team::query()->count());
    }
}
