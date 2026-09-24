<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\AssignmentRole;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Category;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;
use ZipArchive;

/**
 * Exportacion basica a Excel del listado filtrado de actividades: mismo patron y mismas garantias que la de
 * tickets (permisos, alcance, filtros, formato validado, formulas neutralizadas, limite de tasa y bitacora).
 */
class ActivityExportTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

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

    /**
     * @return list<list<string>> Filas de la hoja (con encabezado).
     */
    private function sheetRows(TestResponse $response): array
    {
        $path = $response->baseResponse->getFile()->getPathname();
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false);

        return array_map(fn (array $row): array => array_map(fn ($cell): string => (string) $cell, $row), $rows);
    }

    /**
     * @return list<string> Folios exportados (columna A sin encabezado).
     */
    private function exportedFolios(User $actor, string $query = ''): array
    {
        $response = $this->signIn($actor)->get('/activities/export'.$query)->assertOk();

        return array_column(array_slice($this->sheetRows($response), 1), 0);
    }

    // --- Acceso -------------------------------------------------------------------------------------

    public function test_guest_and_users_without_two_factor_are_refused(): void
    {
        $this->get('/activities/export')->assertRedirect('/login');
        $this->actingAs($this->jefe)->get('/activities/export')->assertRedirect(route('two-factor.challenge'));
    }

    public function test_pending_and_inactive_accounts_are_refused(): void
    {
        $this->signIn(User::factory()->pending()->create())->get('/activities/export')->assertRedirect(route('account.status'));
        $this->signIn(User::factory()->empleado()->inactive()->create())->get('/activities/export')->assertRedirect(route('account.status'));
    }

    public function test_only_jefe_and_coordinator_can_export_and_employee_gets_403_before_validation(): void
    {
        $this->signIn($this->jefe)->get('/activities/export')->assertOk();
        $this->signIn($this->coordA)->get('/activities/export')->assertOk();
        $this->signIn($this->empA1)->get('/activities/export')->assertForbidden();
        $this->signIn($this->empA1)->get('/activities/export?format=csv&status=zzz')->assertForbidden();
    }

    public function test_the_route_does_not_collide_with_the_activity_show_route(): void
    {
        $this->signIn($this->empA1)->get('/activities/export')->assertForbidden();
        $this->signIn($this->jefe)->get('/activities/export')->assertOk();
    }

    public function test_button_is_shown_only_to_those_who_can_export(): void
    {
        $this->signIn($this->jefe)->get('/activities')->assertSee(route('activities.export'), false);
        $this->signIn($this->coordA)->get('/activities')->assertSee(route('activities.export'), false);
        $this->signIn($this->coordA)->get('/dashboard')->assertDontSee(route('activities.export'), false);
        $this->signIn($this->empA1)->get('/activities')->assertForbidden();
    }

    public function test_the_button_carries_the_current_filters(): void
    {
        $html = $this->signIn($this->jefe)->get('/activities?status=pending&q=abc&overdue=1')->assertOk()->getContent();

        $this->assertStringContainsString('/activities/export?status=pending&amp;q=abc&amp;overdue=1', $html);
    }

    public function test_the_truncation_notice_appears_only_above_the_maximum(): void
    {
        config(['tickets.export.max_rows' => 1]);
        $this->makeActivity($this->teamA);

        $this->signIn($this->jefe)->get('/activities')->assertDontSee(__('activities.export.truncated', ['max' => 1]));

        $this->makeActivity($this->teamA);

        $this->signIn($this->jefe)->get('/activities')->assertSee(__('activities.export.truncated', ['max' => 1]));
    }

    public function test_it_is_read_only(): void
    {
        $this->signIn($this->jefe)->post('/activities/export')->assertStatus(405);
    }

    // --- Archivo -------------------------------------------------------------------------------------

    public function test_download_is_an_xlsx_attachment_with_a_server_generated_name_and_no_cache(): void
    {
        Carbon::setTestNow('2026-09-24 15:00:00');

        $response = $this->signIn($this->jefe)->get('/activities/export')->assertOk();

        $this->assertStringContainsString('spreadsheetml.sheet', (string) $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename=activities-20260924-100000.xlsx', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $this->assertNotFalse($zip->locateName('[Content_Types].xml'));
        $zip->close();
    }

    public function test_the_sheet_has_translated_headings_and_the_expected_columns(): void
    {
        Carbon::setTestNow('2026-09-24 15:00:00');
        $category = Category::factory()->create(['name' => 'Soporte']);
        $activity = $this->makeActivity($this->teamA, ['title' => 'Reporte semanal', 'category_id' => $category->id, 'start_date' => '2026-01-05', 'due_date' => '2026-01-10'], $this->empA1);
        $this->assignTo($activity, $this->empA2, AssignmentRole::Colaborador);
        $this->makeSubtask($activity, 'uno', true);
        $this->makeSubtask($activity, 'dos');
        $this->makeSubtask($activity, 'tres');

        $rows = $this->sheetRows($this->signIn($this->jefe)->get('/activities/export')->assertOk());

        $this->assertSame(
            ['Folio', 'Título', 'Estado', 'Prioridad', 'Categoría', 'Equipo', 'Responsable', 'Colaboradores', 'Fecha de inicio', 'Fecha límite', 'Avance', 'Vencida', 'Recurrencia', 'Creada'],
            $rows[0],
        );
        $this->assertSame(
            [$activity->folio, 'Reporte semanal', 'Pendiente', 'Media', 'Soporte', 'Equipo A', 'Emp A1', 'Emp A2', '2026-01-05', '2026-01-10', '33 %', 'Sí', 'Normal'],
            array_slice($rows[1], 0, 13),
        );
    }

    public function test_recurrence_column_distinguishes_template_and_instance_like_the_listing_does(): void
    {
        $template = Activity::factory()->forTeam($this->teamA)->createdBy($this->coordA)->recurring()->create();
        $instance = Activity::factory()->forTeam($this->teamA)->createdBy($this->coordA)->instanceOf($template, '2026-10-05')->create();

        $response = $this->signIn($this->jefe)->get('/activities/export')->assertOk();
        $byFolio = array_column(array_slice($this->sheetRows($response), 1), 12, 0);

        $this->assertSame('Plantilla', $byFolio[$template->folio]);
        $this->assertSame('Instancia de '.$template->folio, $byFolio[$instance->folio]);

        // Exactamente lo que muestra el listado: el filtro `kind` tambien acota la exportacion.
        $this->assertSame([$template->folio], $this->exportedFolios($this->jefe, '?kind=template'));
        $this->assertSame([$instance->folio], $this->exportedFolios($this->jefe, '?kind=instance'));
    }

    public function test_the_export_matches_the_listing_for_the_same_filters(): void
    {
        Activity::factory()->forTeam($this->teamA)->createdBy($this->coordA)->recurring()->create();
        $this->makeActivity($this->teamA);
        $this->makeActivity($this->teamB);

        $listed = $this->signIn($this->jefe)->get('/activities')->viewData('activities')->getCollection()->pluck('folio')->all();

        $this->assertSame($listed, $this->exportedFolios($this->jefe));
    }

    public function test_descriptions_are_not_exported(): void
    {
        $this->makeActivity($this->teamA, ['description' => 'DESCRIPCION-SECRETA-LARGA']);

        $response = $this->signIn($this->jefe)->get('/activities/export')->assertOk();

        $this->assertStringNotContainsString('DESCRIPCION-SECRETA-LARGA', json_encode($this->sheetRows($response), JSON_UNESCAPED_UNICODE));
    }

    // --- Alcance y filtros -------------------------------------------------------------------------------

    public function test_rows_follow_the_role_scope(): void
    {
        $a = $this->makeActivity($this->teamA);
        $b = $this->makeActivity($this->teamB);

        $this->assertEqualsCanonicalizing([$a->folio, $b->folio], $this->exportedFolios($this->jefe));
        $this->assertSame([$a->folio], $this->exportedFolios($this->coordA));
        $this->assertSame([$b->folio], $this->exportedFolios($this->coordB));
    }

    public function test_a_coordinator_also_exports_what_was_assigned_to_them_like_the_listing(): void
    {
        $outside = $this->makeActivity($this->teamB, [], $this->coordA);
        $this->makeActivity($this->teamB);

        $this->assertSame([$outside->folio], $this->exportedFolios($this->coordA));
    }

    public function test_a_coordinator_cannot_widen_the_export_with_a_foreign_team_filter(): void
    {
        $a = $this->makeActivity($this->teamA);
        $b = $this->makeActivity($this->teamB);

        $folios = $this->exportedFolios($this->coordA, '?team_id='.$this->teamB->id);

        $this->assertNotContains($b->folio, $folios);
        $this->assertNotContains($a->folio, $folios);
        $this->assertSame([], $folios);
    }

    public function test_soft_deleted_activities_are_not_exported(): void
    {
        $kept = $this->makeActivity($this->teamA);
        $this->makeActivity($this->teamA)->delete();

        $this->assertSame([$kept->folio], $this->exportedFolios($this->jefe));
    }

    public function test_the_listing_filters_are_respected(): void
    {
        $pending = $this->makeActivity($this->teamA, ['title' => 'Alfa pendiente']);
        $review = Activity::factory()->forTeam($this->teamA)->createdBy($this->coordA)->inReview()->create(['title' => 'Beta revision']);
        $overdue = Activity::factory()->forTeam($this->teamA)->createdBy($this->coordA)->overdue()->create(['title' => 'Gamma vencida']);
        $responsible = $this->makeActivity($this->teamA, ['title' => 'Delta'], $this->empA1);

        $this->assertSame([$review->folio], $this->exportedFolios($this->jefe, '?status='.TicketStatus::InReview->value));
        $this->assertSame([$overdue->folio], $this->exportedFolios($this->jefe, '?overdue=1'));
        $this->assertSame([$pending->folio], $this->exportedFolios($this->jefe, '?q=Alfa'));
        $this->assertSame([$responsible->folio], $this->exportedFolios($this->jefe, '?responsible_id='.$this->empA1->id));
        $this->assertEqualsCanonicalizing([$pending->folio, $overdue->folio, $responsible->folio], $this->exportedFolios($this->jefe, '?status=pending'));
    }

    public function test_sorting_is_respected(): void
    {
        $first = $this->makeActivity($this->teamA, ['title' => 'Aaa', 'created_at' => '2026-01-01 10:00:00']);
        $second = $this->makeActivity($this->teamA, ['title' => 'Bbb', 'created_at' => '2026-02-01 10:00:00']);

        $this->assertSame([$second->folio, $first->folio], $this->exportedFolios($this->jefe));
        $this->assertSame([$first->folio, $second->folio], $this->exportedFolios($this->jefe, '?sort=created_at&direction=asc'));
        $this->assertSame([$first->folio, $second->folio], $this->exportedFolios($this->jefe, '?sort=title&direction=asc'));
    }

    public function test_the_row_count_is_capped_by_configuration(): void
    {
        config(['tickets.export.max_rows' => 2]);
        foreach (range(1, 3) as $i) {
            $this->makeActivity($this->teamA);
        }

        $this->assertCount(2, $this->exportedFolios($this->jefe));

        $entry = ActivityLogEntry::query()->where('event', 'exported')->latest('id')->firstOrFail();
        $this->assertSame(2, $entry->getProperty('rows'));
        $this->assertTrue($entry->getProperty('truncated'));
    }

    public function test_an_empty_result_still_produces_a_valid_file_with_headings(): void
    {
        $rows = $this->sheetRows($this->signIn($this->coordA)->get('/activities/export')->assertOk());

        $this->assertCount(1, $rows);
        $this->assertSame('Folio', $rows[0][0]);
    }

    // --- Validacion --------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidQueries(): array
    {
        return [
            'formato csv' => ['format=csv', 'format'],
            'formato pdf' => ['format=pdf', 'format'],
            'formato con ruta' => ['format=..%2F..%2Fetc%2Fpasswd', 'format'],
            'formato arreglo' => ['format[]=xlsx', 'format'],
            'estado invalido' => ['status=zzz', 'status'],
            'prioridad invalida' => ['priority=extrema', 'priority'],
            'tipo invalido' => ['kind=otro', 'kind'],
            'orden fuera de la lista blanca' => ['sort=description', 'sort'],
            'equipo inexistente' => ['team_id=999999', 'team_id'],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_parameters_are_rejected(string $query, string $field): void
    {
        $this->signIn($this->jefe)->get('/activities/export?'.$query)->assertSessionHasErrors($field);
    }

    public function test_format_xlsx_is_accepted_explicitly(): void
    {
        $this->signIn($this->jefe)->get('/activities/export?format=xlsx')->assertOk();
    }

    // --- Inyeccion de formulas -----------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function formulaPayloads(): array
    {
        return [
            'igual' => ['=HYPERLINK("http://evil.example","clic")'],
            'suma' => ['+cmd|\' /C calc\'!A0'],
            'resta' => ['-2+3+cmd|\' /C calc\'!A0'],
            'arroba' => ['@SUM(1+1)'],
            'tabulador' => ["\t=1+1"],
            'retorno' => ["\r=1+1"],
        ];
    }

    #[DataProvider('formulaPayloads')]
    public function test_formula_injection_is_neutralized_in_every_text_cell(string $payload): void
    {
        $this->teamA->forceFill(['name' => $payload.' eq'])->save();
        $this->empA1->forceFill(['name' => $payload.' resp'])->save();
        $this->empA2->forceFill(['name' => $payload.' colab'])->save();
        $category = Category::factory()->create(['name' => $payload.' cat']);
        $activity = $this->makeActivity($this->teamA, ['title' => $payload, 'category_id' => $category->id], $this->empA1);
        $this->assignTo($activity, $this->empA2, AssignmentRole::Colaborador);

        $response = $this->signIn($this->jefe)->get('/activities/export')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $sheet = IOFactory::load($path)->getActiveSheet();
        // El XML normaliza el retorno de carro a salto de linea al releer el archivo.
        $normalize = fn (string $text): string => str_replace("\r", "\n", $text);

        foreach (['B2' => $payload, 'E2' => $payload.' cat', 'F2' => $payload.' eq', 'G2' => $payload.' resp', 'H2' => $payload.' colab'] as $coordinate => $original) {
            $cell = $sheet->getCell($coordinate);
            $this->assertSame($normalize("'".$original), $normalize((string) $cell->getValue()), $coordinate);
            $this->assertSame('s', $cell->getDataType(), "{$coordinate} debe ser texto, no formula");
        }

        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertStringNotContainsString('<f>', (string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
    }

    public function test_plain_text_is_not_altered(): void
    {
        $this->makeActivity($this->teamA, ['title' => 'Revisión de "cámara" #3 = ok']);

        $rows = $this->sheetRows($this->signIn($this->jefe)->get('/activities/export')->assertOk());

        $this->assertSame('Revisión de "cámara" #3 = ok', $rows[1][1]);
    }

    // --- Bitacora y limites ---------------------------------------------------------------------------------

    public function test_every_export_is_audited_with_actor_type_rows_and_filters(): void
    {
        $this->makeActivity($this->teamA);
        $this->makeActivity($this->teamA);
        ActivityLogEntry::query()->delete();

        $this->signIn($this->coordA)->get('/activities/export?status=pending&q=abc')->assertOk();

        $entry = ActivityLogEntry::query()->where('event', 'exported')->sole();
        $this->assertSame('exports', $entry->log_name);
        $this->assertSame($this->coordA->id, $entry->causer_id);
        $this->assertSame('activities', $entry->getProperty('type'));
        $this->assertSame('xlsx', $entry->getProperty('format'));
        $this->assertSame(0, $entry->getProperty('rows'));
        $this->assertSame(['status' => 'pending', 'q' => 'abc'], $entry->getProperty('filters'));
        $this->assertNull($entry->subject_type);
    }

    public function test_refused_requests_leave_no_audit_entry(): void
    {
        ActivityLogEntry::query()->delete();

        $this->signIn($this->empA1)->get('/activities/export')->assertForbidden();
        $this->signIn($this->jefe)->get('/activities/export?format=csv');

        $this->assertSame(0, ActivityLogEntry::query()->where('event', 'exported')->count());
    }

    public function test_the_ticket_export_still_audits_its_own_type(): void
    {
        ActivityLogEntry::query()->delete();

        $this->signIn($this->jefe)->get('/tickets/export')->assertOk();

        $this->assertSame('tickets', ActivityLogEntry::query()->where('event', 'exported')->sole()->getProperty('type'));
    }

    public function test_the_export_is_rate_limited_per_user_and_shared_with_the_ticket_export(): void
    {
        RateLimiter::clear('export|'.$this->jefe->id);
        $limit = (int) config('tickets.rate_limits.export_per_hour');

        for ($i = 0; $i < $limit - 1; $i++) {
            $this->signIn($this->jefe)->get('/activities/export')->assertOk();
        }
        $this->signIn($this->jefe)->get('/tickets/export')->assertOk();

        $this->signIn($this->jefe)->get('/activities/export')->assertStatus(429);
        $this->signIn($this->coordA)->get('/activities/export')->assertOk();
    }

    public function test_query_count_does_not_grow_with_the_number_of_activities(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->signIn($this->jefe)->get('/activities/export')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $first = $this->makeActivity($this->teamA, [], $this->empA1);
        $this->makeSubtask($first, 'a', true);
        // La linea base ya incluye una instancia: el eager loading de `parent` solo corre si hay alguna.
        $seedTemplate = Activity::factory()->forTeam($this->teamA)->createdBy($this->coordA)->recurring()->create();
        Activity::factory()->forTeam($this->teamA)->createdBy($this->coordA)->instanceOf($seedTemplate, '2026-09-30')->create();
        $count();
        $before = $count();

        $template = Activity::factory()->forTeam($this->teamB)->createdBy($this->coordB)->recurring()->create();
        foreach (range(1, 12) as $i) {
            $activity = Activity::factory()->forTeam($this->teamB)->createdBy($this->coordB)->instanceOf($template, "2026-10-{$i}")->create();
            $this->assignTo($activity, $this->empB1);
            $this->assignTo($activity, $this->empA2, AssignmentRole::Colaborador);
            $this->makeSubtask($activity, "s{$i}", $i % 2 === 0);
        }

        $this->assertSame($before, $count());
    }
}
