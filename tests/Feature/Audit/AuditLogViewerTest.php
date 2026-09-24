<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\Ticket;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuditLogService;
use App\Support\MorphMap;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;
use Tests\Concerns\BuildsTicketScenario;
use Tests\DatabaseTestCase;

/**
 * Visor de bitacora (solo jefe, solo lectura): acceso, filtros, escapado y ausencia de secretos.
 */
class AuditLogViewerTest extends DatabaseTestCase
{
    use BuildsTicketScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        // Las altas del escenario dejan filas de bitacora; cada prueba parte de una bitacora vacia y controlada.
        ActivityLogEntry::query()->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entry(array $attributes = []): ActivityLogEntry
    {
        return ActivityLogEntry::query()->create([
            'log_name' => 'tickets',
            'description' => 'status_changed',
            'event' => 'status_changed',
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<int>
     */
    private function listedIds(array $query = []): array
    {
        return $this->signIn($this->jefe)->get('/audit-log?'.http_build_query($query))->assertOk()
            ->viewData('entries')->getCollection()->pluck('id')->all();
    }

    // --- Acceso ----------------------------------------------------------------------------------------

    public function test_guest_is_redirected_and_two_factor_is_required(): void
    {
        $this->get('/audit-log')->assertRedirect('/login');
        $this->actingAs($this->jefe)->get('/audit-log')->assertRedirect(route('two-factor.challenge'));
    }

    public function test_only_the_jefe_can_view_the_audit_log(): void
    {
        $this->signIn($this->jefe)->get('/audit-log')->assertOk();
        $this->signIn($this->coordA)->get('/audit-log')->assertForbidden();
        $this->signIn($this->empA1)->get('/audit-log')->assertForbidden();
        $this->signIn($this->empA1)->getJson('/audit-log?subject=nope')->assertForbidden();
    }

    public function test_pending_and_inactive_users_cannot_view_it(): void
    {
        $this->signIn(User::factory()->pending()->create())->get('/audit-log')->assertRedirect(route('account.status'));
        $this->signIn(User::factory()->inactive()->create())->get('/audit-log')->assertRedirect(route('account.status'));
    }

    public function test_the_permission_is_held_only_by_the_jefe_role(): void
    {
        $this->assertTrue($this->jefe->can('viewAny', ActivityLogEntry::class));
        $this->assertFalse($this->coordA->can('viewAny', ActivityLogEntry::class));
        $this->assertFalse($this->empA1->can('viewAny', ActivityLogEntry::class));
    }

    public function test_the_viewer_is_read_only(): void
    {
        $this->entry();

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->signIn($this->jefe)->{$method}('/audit-log')->assertStatus(405);
        }

        $this->assertSame(1, ActivityLogEntry::query()->count());
    }

    public function test_sidebar_link_is_only_for_the_jefe(): void
    {
        $this->signIn($this->jefe)->get('/dashboard')->assertSee(route('audit.index'), false);
        $this->signIn($this->coordA)->get('/dashboard')->assertDontSee(route('audit.index'), false);
        $this->signIn($this->empA1)->get('/dashboard')->assertDontSee(route('audit.index'), false);
    }

    // --- Listado y filtros ------------------------------------------------------------------------------

    public function test_entries_are_listed_newest_first_and_paginated(): void
    {
        foreach (range(1, 30) as $i) {
            $this->entry(['description' => "evento {$i}"]);
        }

        $page1 = $this->signIn($this->jefe)->get('/audit-log')->assertOk()->viewData('entries');
        $this->assertSame(25, $page1->count());
        $this->assertSame(30, $page1->total());
        $this->assertGreaterThan($page1->last()->id, $page1->first()->id);

        $this->signIn($this->jefe)->get('/audit-log?page=2')->assertOk()->assertSee('evento 1')->assertDontSee('evento 30');
    }

    public function test_filter_by_causer(): void
    {
        $byJefe = $this->entry(['causer_type' => 'App\Models\User', 'causer_id' => $this->jefe->id]);
        $this->entry(['causer_type' => 'App\Models\User', 'causer_id' => $this->coordA->id]);

        $this->assertSame([$byJefe->id], $this->listedIds(['causer_id' => $this->jefe->id]));
    }

    public function test_filter_by_event(): void
    {
        $login = $this->entry(['event' => 'login', 'description' => 'login']);
        $this->entry(['event' => 'logout', 'description' => 'logout']);

        $this->assertSame([$login->id], $this->listedIds(['event' => 'login']));
    }

    public function test_filter_by_subject_type_uses_the_morph_map_alias(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->create();
        ActivityLogEntry::query()->delete(); // el alta del ticket ya registro su propia fila
        $forTicket = $this->entry(['subject_type' => (new Ticket)->getMorphClass(), 'subject_id' => $ticket->id]);
        $forUser = $this->entry(['subject_type' => 'App\Models\User', 'subject_id' => $this->empA1->id]);
        $forTeam = $this->entry(['subject_type' => 'App\Models\Team', 'subject_id' => $this->teamA->id]);

        $this->assertSame([$forTicket->id], $this->listedIds(['subject' => 'ticket']));
        $this->assertSame([$forUser->id], $this->listedIds(['subject' => 'user']));
        $this->assertSame([$forTeam->id], $this->listedIds(['subject' => 'team']));
    }

    public function test_filter_by_date_range_uses_business_days(): void
    {
        // 2026-09-20 03:00 UTC = 2026-09-19 22:00 en Cancun.
        $lateLocal = $this->entry(['created_at' => '2026-09-20 03:00:00']);
        $nextDay = $this->entry(['created_at' => '2026-09-20 12:00:00']);

        $this->assertSame([$lateLocal->id], $this->listedIds(['from' => '2026-09-19', 'to' => '2026-09-19']));
        $this->assertSame([$nextDay->id], $this->listedIds(['from' => '2026-09-20', 'to' => '2026-09-20']));
        $this->assertEqualsCanonicalizing([$lateLocal->id, $nextDay->id], $this->listedIds(['from' => '2026-09-19', 'to' => '2026-09-20']));
        $this->assertSame([], $this->listedIds(['from' => '2026-09-21']));
    }

    public function test_search_matches_description_event_log_name_and_payload(): void
    {
        $byDescription = $this->entry(['description' => 'algo-unico-aaa', 'event' => 'created']);
        $byPayload = $this->entry(['event' => 'assigned', 'description' => 'assigned', 'properties' => ['folio' => 'TM-2026-7777']]);
        $byLog = $this->entry(['log_name' => 'log-unico-zzz', 'event' => 'updated', 'description' => 'updated']);

        $this->assertSame([$byDescription->id], $this->listedIds(['q' => 'unico-aaa']));
        $this->assertSame([$byPayload->id], $this->listedIds(['q' => 'TM-2026-7777']));
        $this->assertSame([$byLog->id], $this->listedIds(['q' => 'unico-zzz']));
    }

    public function test_search_treats_wildcards_as_literals(): void
    {
        $this->entry(['description' => 'uno', 'event' => 'x', 'log_name' => 'y']);
        $this->entry(['description' => 'a_b', 'event' => 'x', 'log_name' => 'y']);

        $this->assertSame(1, count($this->listedIds(['q' => '_'])));
        $this->assertSame(0, count($this->listedIds(['q' => '%'])));
    }

    public function test_filters_can_be_combined(): void
    {
        $match = $this->entry(['event' => 'approved', 'description' => 'approved', 'causer_type' => 'App\Models\User', 'causer_id' => $this->jefe->id, 'subject_type' => 'App\Models\User', 'subject_id' => $this->empA1->id]);
        $this->entry(['event' => 'approved', 'description' => 'approved', 'causer_type' => 'App\Models\User', 'causer_id' => $this->coordA->id, 'subject_type' => 'App\Models\User', 'subject_id' => $this->empA1->id]);
        $this->entry(['event' => 'rejected', 'description' => 'rejected', 'causer_type' => 'App\Models\User', 'causer_id' => $this->jefe->id]);

        $this->assertSame([$match->id], $this->listedIds(['event' => 'approved', 'causer_id' => $this->jefe->id, 'subject' => 'user']));
    }

    // --- Validacion --------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidFilters(): array
    {
        return [
            'tipo de sujeto fuera de la lista blanca' => ['subject=App%5CModels%5CUser', 'subject'],
            'tipo de clase arbitraria' => ['subject=Illuminate%5CFoundation%5CApplication', 'subject'],
            'evento con caracteres invalidos' => ['event=login%27%20OR%201%3D1', 'event'],
            'evento demasiado largo' => ['event='.'a1234567890123456789012345678901234567890123456789012345678901', 'event'],
            'usuario inexistente' => ['causer_id=999999', 'causer_id'],
            'usuario no numerico' => ['causer_id=abc', 'causer_id'],
            'fecha invalida' => ['from=ayer', 'from'],
            'rango invertido' => ['from=2026-09-20&to=2026-09-01', 'from'],
            'busqueda demasiado larga' => ['q='.'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'q'],
            'pagina invalida' => ['page=0', 'page'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected(string $query, string $field): void
    {
        $this->signIn($this->jefe)->get('/audit-log?'.$query)->assertSessionHasErrors($field);
    }

    public function test_subject_whitelist_matches_the_morph_map(): void
    {
        foreach (AuditLogService::SUBJECT_TYPES as $alias) {
            $this->assertArrayHasKey($alias, MorphMap::aliases());
        }
    }

    // --- Seguridad de la salida ---------------------------------------------------------------------------

    public function test_sensitive_keys_are_never_rendered(): void
    {
        $this->entry([
            'event' => 'updated',
            'description' => 'updated',
            'attribute_changes' => [
                'old' => ['password' => 'OLD-HASH-VALUE', 'remember_token' => 'OLD-REMEMBER', 'name' => 'Nombre viejo'],
                'attributes' => ['password' => 'NEW-HASH-VALUE', 'two_factor_secret' => 'TOTP-SECRET-XYZ', 'name' => 'Nombre nuevo'],
            ],
            'properties' => [
                'ip' => '203.0.113.9',
                'token' => 'API-TOKEN-123',
                'recovery_codes' => ['RC-AAA-111'],
                'nested' => ['secret' => 'NESTED-SECRET', 'visible' => 'dato visible'],
            ],
        ]);

        $html = $this->signIn($this->jefe)->get('/audit-log')->assertOk()->getContent();

        foreach (['OLD-HASH-VALUE', 'NEW-HASH-VALUE', 'OLD-REMEMBER', 'TOTP-SECRET-XYZ', 'API-TOKEN-123', 'RC-AAA-111', 'NESTED-SECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $html, $secret);
        }
        $this->assertStringNotContainsString('two_factor_secret', $html);
        $this->assertStringContainsString('Nombre viejo', $html);
        $this->assertStringContainsString('Nombre nuevo', $html);
        $this->assertStringContainsString('203.0.113.9', $html);
        $this->assertStringContainsString('dato visible', $html);
    }

    public function test_audit_logger_output_and_the_viewer_layer_both_drop_secrets(): void
    {
        // Segunda barrera: aunque una fila ya guardada trajera la llave, no se muestra.
        app(AuditLogger::class)->record('users', 'approved', $this->empA1, $this->jefe, ['status' => 'pending'], ['status' => 'active'], ['password' => 'IGNORED-PW', 'reason' => 'ok']);
        DB::table('activity_log')->where('event', 'approved')->update(['properties' => json_encode(['password' => 'STORED-PW', 'reason' => 'ok'])]);

        $html = $this->signIn($this->jefe)->get('/audit-log')->assertOk()->getContent();

        $this->assertStringNotContainsString('STORED-PW', $html);
        $this->assertStringNotContainsString('IGNORED-PW', $html);
        $this->assertStringContainsString('reason', $html);
    }

    public function test_every_value_is_escaped(): void
    {
        $payload = '<script>alert("x")</script><img src=x onerror=alert(1)>';
        $this->empA1->forceFill(['name' => $payload])->save();
        $this->entry([
            'description' => $payload,
            'event' => 'updated',
            'subject_type' => 'App\Models\User',
            'subject_id' => $this->empA1->id,
            'causer_type' => 'App\Models\User',
            'causer_id' => $this->empA1->id,
            'attribute_changes' => ['old' => ['name' => $payload], 'attributes' => ['name' => $payload.'2']],
            'properties' => ['nota' => $payload],
        ]);

        $html = $this->signIn($this->jefe)->get('/audit-log')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString(e($payload), $html);
    }

    public function test_the_page_has_no_inline_scripts_and_a_strict_csp(): void
    {
        $this->entry();
        $response = $this->signIn($this->jefe)->get('/audit-log')->assertOk();

        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $response->getContent());
        $this->assertStringNotContainsString('unsafe-eval', (string) $response->headers->get('Content-Security-Policy'));
    }

    // --- Datos de origen inusuales ------------------------------------------------------------------------

    public function test_rows_with_a_subject_type_outside_the_morph_map_are_excluded_without_breaking_the_page(): void
    {
        $this->entry(['description' => 'visible']);
        DB::table('activity_log')->insert(['log_name' => 'x', 'description' => 'legacy-row', 'event' => 'created', 'subject_type' => 'App\Legacy\Thing', 'subject_id' => 5, 'created_at' => now(), 'updated_at' => now()]);

        $this->signIn($this->jefe)->get('/audit-log')->assertOk()->assertSee('visible')->assertDontSee('legacy-row');
    }

    public function test_a_deleted_subject_and_a_system_event_render_safely(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->create();
        $this->entry(['subject_type' => 'ticket', 'subject_id' => $ticket->id, 'description' => 'gone']);
        $ticket->delete();
        $this->entry(['subject_type' => 'ticket', 'subject_id' => 987654, 'description' => 'never-existed']);

        $this->signIn($this->jefe)->get('/audit-log')->assertOk()->assertSee('#987654')->assertSee(__('audit.system'));
    }

    public function test_subject_and_causer_names_are_shown(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->create(['title' => 'Impresora rota']);
        $this->entry(['subject_type' => 'ticket', 'subject_id' => $ticket->id, 'causer_type' => 'App\Models\User', 'causer_id' => $this->coordA->id]);

        $this->signIn($this->jefe)->get('/audit-log')->assertOk()->assertSee($ticket->folio.' · Impresora rota')->assertSee('Coord A');
    }

    // --- Rendimiento y limites ---------------------------------------------------------------------------

    public function test_query_count_does_not_grow_with_the_number_of_entries(): void
    {
        $seed = function (int $n): void {
            foreach (range(1, $n) as $i) {
                $ticket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->create();
                $this->entry(['subject_type' => 'ticket', 'subject_id' => $ticket->id, 'causer_type' => 'App\Models\User', 'causer_id' => $this->coordA->id]);
                $this->entry(['subject_type' => 'App\Models\User', 'subject_id' => $this->empA1->id, 'causer_type' => 'App\Models\User', 'causer_id' => $this->jefe->id]);
                $this->entry(['subject_type' => 'App\Models\Team', 'subject_id' => $this->teamA->id]);
            }
        };
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->signIn($this->jefe)->get('/audit-log')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $seed(2);
        $count();
        $before = $count();
        $seed(8);

        $this->assertSame($before, $count());
    }

    public function test_the_viewer_is_rate_limited_per_user(): void
    {
        RateLimiter::clear('audit-view|'.$this->jefe->id);
        $limit = (int) config('tickets.rate_limits.audit_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this->signIn($this->jefe)->get('/audit-log')->assertOk();
        }

        $this->signIn($this->jefe)->get('/audit-log')->assertStatus(429);
    }
}
