<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Models\Activity;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;
use Tests\DatabaseTestCase;
use ZipArchive;

/**
 * Excel en la nube (Microsoft Graph simulado con Http::fake): token, subida, contenido del libro, reintentos,
 * configuracion invalida y que nunca se filtren secretos.
 */
class CloudSheetSyncTest extends DatabaseTestCase
{
    private const TOKEN_URL = 'https://login.microsoftonline.com/contoso-tenant/oauth2/v2.0/token';

    private const UPLOAD_URL = 'https://graph.microsoft.com/v1.0/drives/b!drive-123/root:/Reportes/tickets%20y%20actividades.xlsx:/content';

    private Team $teamA;

    private Team $teamB;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Cache::flush();
        config(['tickets.cloud_sync' => [
            'enabled' => true,
            'tenant_id' => 'contoso-tenant',
            'client_id' => '11111111-2222-3333-4444-555555555555',
            'client_secret' => 'super-secreto-no-filtrar',
            'drive_id' => 'b!drive-123',
            'path' => 'Reportes/tickets y actividades.xlsx',
            'max_rows' => 20000,
            'timeout_seconds' => 5,
        ]]);

        $this->teamA = Team::factory()->create(['name' => 'Equipo A']);
        $this->teamB = Team::factory()->create(['name' => 'Equipo B']);
    }

    private function fakeGraph(int $uploadStatus = 201): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok-abc', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*' => Http::response(['id' => 'item'], $uploadStatus),
        ]);
    }

    private function uploadedWorkbookPath(): string
    {
        $upload = Http::recorded(fn (Request $request): bool => $request->method() === 'PUT')->last()[0];
        $path = tempnam(sys_get_temp_dir(), 'cloud').'.xlsx';
        file_put_contents($path, $upload->body());

        return $path;
    }

    public function test_disabled_sync_does_nothing(): void
    {
        config(['tickets.cloud_sync.enabled' => false]);
        Http::fake();

        $this->artisan('exports:sync-cloud')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_uploads_workbook_with_all_teams_to_the_exact_graph_url(): void
    {
        $this->fakeGraph();
        Ticket::factory()->forTeam($this->teamA)->create(['title' => 'Ticket A']);
        Ticket::factory()->forTeam($this->teamB)->create(['title' => 'Ticket B']);
        Ticket::factory()->forTeam($this->teamA)->create(['title' => 'Ticket borrado'])->delete();
        Activity::factory()->forTeam($this->teamB)->create(['title' => 'Actividad B']);
        Activity::factory()->forTeam($this->teamA)->recurring()->create(['title' => 'Plantilla']);

        $this->artisan('exports:sync-cloud')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->url() === self::TOKEN_URL
            && $request['grant_type'] === 'client_credentials'
            && $request['scope'] === 'https://graph.microsoft.com/.default');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === self::UPLOAD_URL
            && $request->hasHeader('Authorization', 'Bearer tok-abc')
            && str_starts_with($request->header('Content-Type')[0], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));

        $book = IOFactory::load($this->uploadedWorkbookPath());
        $this->assertSame(['Tickets', 'Actividades', 'Info'], $book->getSheetNames());

        $tickets = json_encode($book->getSheet(0)->toArray());
        $this->assertStringContainsString('Ticket A', $tickets);
        $this->assertStringContainsString('Ticket B', $tickets);
        $this->assertStringNotContainsString('Ticket borrado', $tickets);

        $activities = json_encode($book->getSheet(1)->toArray());
        $this->assertStringContainsString('Actividad B', $activities);
        $this->assertStringNotContainsString('Plantilla', $activities);

        $this->assertTrue(ActivityLogEntry::query()->where('event', 'cloud_synced')->exists());
    }

    public function test_descriptions_are_not_exported_and_formulas_are_neutralized(): void
    {
        $this->fakeGraph();
        Ticket::factory()->forTeam($this->teamA)->create(['title' => '=HYPERLINK("http://x")', 'description' => 'DESCRIPCION-SECRETA']);

        $this->artisan('exports:sync-cloud')->assertSuccessful();

        $path = $this->uploadedWorkbookPath();
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = '';

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $xml .= (string) $zip->getFromIndex($i);
        }

        $zip->close();
        $this->assertStringNotContainsString('<f>', $xml);
        $this->assertStringNotContainsString('DESCRIPCION-SECRETA', $xml);
    }

    public function test_token_is_cached_between_runs(): void
    {
        $this->fakeGraph();

        $this->artisan('exports:sync-cloud')->assertSuccessful();
        $this->artisan('exports:sync-cloud')->assertSuccessful();

        Http::assertSentCount(3);
    }

    public function test_retries_on_throttling_and_server_errors(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok-abc', 'expires_in' => 3600]),
            'https://graph.microsoft.com/*' => Http::sequence()
                ->push([], 429, ['Retry-After' => '3'])
                ->push([], 503)
                ->push(['id' => 'item'], 200),
        ]);

        $this->artisan('exports:sync-cloud')->assertSuccessful();

        Http::assertSentCount(4);
        Sleep::assertSleptTimes(2);
    }

    public function test_definitive_failure_exits_with_error_logs_audit_and_never_leaks_secrets(): void
    {
        $this->fakeGraph(403);

        $this->artisan('exports:sync-cloud')->assertFailed();

        $entry = ActivityLogEntry::query()->where('event', 'cloud_sync_failed')->firstOrFail();
        $this->assertStringNotContainsString('super-secreto-no-filtrar', json_encode($entry->toArray()));
        $this->assertStringNotContainsString('tok-abc', json_encode($entry->toArray()));
        $this->assertStringContainsString('403', (string) $entry->properties['reason']);
    }

    public function test_invalid_token_response_fails_without_uploading(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401)]);

        $this->artisan('exports:sync-cloud')->assertFailed();

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_invalid_configuration_fails_before_any_request(): void
    {
        Http::fake();

        foreach ([
            ['path' => '../../otro-sitio/archivo.xlsx'],
            ['path' => 'Reportes/archivo.exe'],
            ['drive_id' => 'drive/../../users'],
            ['tenant_id' => 'evil.com/x?'],
            ['client_secret' => ''],
        ] as $override) {
            config(['tickets.cloud_sync' => [...config('tickets.cloud_sync'), ...$override]]);
            $this->artisan('exports:sync-cloud')->assertFailed();
            config(['tickets.cloud_sync' => [...config('tickets.cloud_sync'), 'path' => 'Reportes/tickets y actividades.xlsx', 'drive_id' => 'b!drive-123', 'tenant_id' => 'contoso-tenant', 'client_secret' => 'super-secreto-no-filtrar']]);
        }

        Http::assertNothingSent();
    }

    public function test_rows_are_capped_and_the_info_sheet_warns(): void
    {
        $this->fakeGraph();
        config(['tickets.cloud_sync.max_rows' => 2]);
        Ticket::factory()->count(3)->forTeam($this->teamA)->create();

        $this->artisan('exports:sync-cloud')->assertSuccessful();

        $book = IOFactory::load($this->uploadedWorkbookPath());
        $this->assertCount(3, $book->getSheet(0)->toArray()); // encabezado + 2
        $this->assertStringContainsString('recortó', json_encode($book->getSheet(2)->toArray(), JSON_UNESCAPED_UNICODE));
    }

    public function test_query_count_does_not_grow_with_rows(): void
    {
        $this->fakeGraph();
        $user = User::factory()->empleado()->create(['team_id' => $this->teamA->id]);
        Ticket::factory()->forTeam($this->teamA)->assignedTo($user)->create();
        Activity::factory()->forTeam($this->teamA)->assignedTo($user)->create();

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->artisan('exports:sync-cloud')->assertSuccessful();

            return count(DB::getQueryLog());
        };

        $base = $count();
        Ticket::factory()->count(10)->forTeam($this->teamB)->assignedTo($user)->create();
        Activity::factory()->count(10)->forTeam($this->teamB)->assignedTo($user)->create();

        $this->assertSame($base, $count());
    }

    public function test_hourly_schedule_runs_only_when_enabled(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'exports:sync-cloud'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->filtersPass($this->app));

        config(['tickets.cloud_sync.enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));
    }
}
