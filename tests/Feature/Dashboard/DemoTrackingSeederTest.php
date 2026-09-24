<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Activity;
use App\Models\Ticket;
use App\Models\User;
use App\Services\DashboardMetricsService;
use App\Support\Dashboard\DashboardFilters;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoTrackingSeeder;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

class DemoTrackingSeederTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['tickets.dashboard.cache_ttl' => 0]);
        $this->seed(DemoDataSeeder::class);
    }

    public function test_demo_data_gives_the_panel_something_useful_to_show(): void
    {
        $jefe = User::query()->where('email', 'jefe@demo.test')->firstOrFail();

        $report = app(DashboardMetricsService::class)->report($jefe, DashboardFilters::fromValidated([]));

        $this->assertGreaterThan(0, $report['cards']['open']['total']);
        $this->assertGreaterThan(0, $report['cards']['overdue']['total']);
        $this->assertGreaterThan(0, $report['cards']['completed']['total']);
        $this->assertNotNull($report['closing']['average_seconds']);
        $this->assertGreaterThan(0, $report['workload']['total_people']);
        $this->assertGreaterThan(3, count(array_filter($report['trend']['points'], fn (array $p): bool => $p['created'] > 0)), 'la creacion se reparte en varios dias');
    }

    public function test_the_seeder_is_idempotent_and_does_not_touch_recurrence_series(): void
    {
        $snapshot = fn (): array => [
            Ticket::query()->orderBy('id')->pluck('created_at', 'id')->map(fn ($v) => (string) $v)->all(),
            Activity::query()->orderBy('id')->pluck('created_at', 'id')->map(fn ($v) => (string) $v)->all(),
        ];
        $templates = Activity::query()->whereNotNull('recurrence_rule')->orWhereNotNull('parent_activity_id')->pluck('created_at', 'id')->map(fn ($v) => (string) $v)->all();

        $before = $snapshot();
        (new DemoTrackingSeeder)->run();
        (new DemoTrackingSeeder)->run();

        $this->assertSame($before, $snapshot());
        $this->assertSame($templates, Activity::query()->whereNotNull('recurrence_rule')->orWhereNotNull('parent_activity_id')->pluck('created_at', 'id')->map(fn ($v) => (string) $v)->all());
    }

    public function test_it_does_not_write_audit_entries(): void
    {
        $before = DB::table('activity_log')->count();

        (new DemoTrackingSeeder)->run();

        $this->assertSame($before, DB::table('activity_log')->count());
    }
}
