<?php

declare(strict_types=1);

namespace Tests\Feature\Emails;

use App\Enums\IncomingEmailStatus;
use App\Models\Coordination;
use App\Models\IncomingEmail;
use App\Models\SpreadsheetSource;
use App\Models\Station;
use App\Models\Team;
use App\Models\User;
use App\Services\SheetInbox\SheetInboxImporter;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\DatabaseTestCase;

/**
 * Carga manual del Excel de "Mi bandeja": mismas columnas, dedupe y revisión humana que la lectura automática,
 * pero sin cuenta de Microsoft ni enlace de OneDrive.
 */
class SheetManualUploadTest extends DatabaseTestCase
{
    private Coordination $own;

    private Coordination $other;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $station = Station::factory()->create(['name' => 'Zonal']);
        $this->own = Coordination::factory()->create(['station_id' => $station->id, 'name' => 'Propia']);
        $this->other = Coordination::factory()->create(['station_id' => $station->id, 'name' => 'Ajena']);
        $team = Team::factory()->create(['coordination_id' => $this->own->id]);
        $this->coordinator = User::factory()->coordinador()->inTeams($team)->create(['team_id' => $team->id]);
    }

    private function manualSource(Coordination $coordination): SpreadsheetSource
    {
        return SpreadsheetSource::query()->create([
            'coordination_id' => $coordination->id,
            'name' => 'Seguimiento',
            'share_url' => '',
            'header_row' => 1,
            'subject_column' => 'Asunto',
            'body_columns' => ['Detalle'],
            'requester_email_column' => 'Correo',
            'key_column' => 'Folio',
            'active' => true,
        ]);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function file(array $rows, string $name = 'bandeja.xlsx'): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'xls');
        (new Xlsx($book))->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    public function test_upload_imports_new_rows_for_review_without_a_microsoft_account(): void
    {
        $source = $this->manualSource($this->own);
        $this->assertFalse($source->isConnected());

        $this->signIn($this->coordinator)->post(route('sheet-sources.upload', $this->own), [
            'file' => $this->file([['Folio', 'Asunto', 'Detalle', 'Correo'], ['A-1', 'Oficio uno', 'Revisar', 'ana@example.com'], ['A-2', 'Oficio dos', 'Atender', '']]),
        ])->assertRedirect(route('inbox-settings.index'))->assertSessionHas('notice');

        $emails = IncomingEmail::query()->where('coordination_id', $this->own->id)->orderBy('id')->get();
        $this->assertCount(2, $emails);
        $this->assertSame('Oficio uno', $emails[0]->subject);
        $this->assertSame('ana@example.com', $emails[0]->from_email);
        $this->assertSame(IncomingEmailStatus::PendingReview, $emails[0]->status);
        $this->assertNotNull($source->fresh()->last_synced_at);
        $this->assertNull($source->fresh()->last_error);
    }

    public function test_uploading_the_same_file_again_does_not_duplicate_rows(): void
    {
        $this->manualSource($this->own);
        $rows = [['Folio', 'Asunto', 'Detalle', 'Correo'], ['A-1', 'Oficio uno', 'Revisar', ''], ['A-2', 'Oficio dos', 'Atender', '']];

        $this->signIn($this->coordinator)->post(route('sheet-sources.upload', $this->own), ['file' => $this->file($rows)]);
        $rows[] = ['A-3', 'Oficio tres', 'Nuevo', ''];
        $this->signIn($this->coordinator)->post(route('sheet-sources.upload', $this->own), ['file' => $this->file($rows)]);

        $this->assertSame(3, IncomingEmail::query()->where('coordination_id', $this->own->id)->count());
    }

    public function test_other_coordination_is_404_and_employee_is_403(): void
    {
        $this->manualSource($this->other);
        $file = fn () => $this->file([['Folio', 'Asunto'], ['A-1', 'X']]);

        $this->signIn($this->coordinator)->post(route('sheet-sources.upload', $this->other), ['file' => $file()])->assertNotFound();
        $this->assertSame(0, IncomingEmail::query()->count());

        $employee = User::factory()->empleado()->create();
        $this->signIn($employee)->post(route('sheet-sources.upload', $this->own), ['file' => $file()])->assertForbidden();
    }

    public function test_without_a_configured_sheet_it_asks_to_configure_first(): void
    {
        $this->signIn($this->coordinator)->post(route('sheet-sources.upload', $this->own), [
            'file' => $this->file([['Folio', 'Asunto'], ['A-1', 'X']]),
        ])->assertRedirect(route('inbox-settings.index'))->assertSessionHas('error');

        $this->assertSame(0, IncomingEmail::query()->count());
    }

    public function test_a_file_that_is_not_a_workbook_is_rejected_and_imports_nothing(): void
    {
        $source = $this->manualSource($this->own);

        $this->signIn($this->coordinator)->post(route('sheet-sources.upload', $this->own), [
            'file' => UploadedFile::fake()->createWithContent('falso.xlsx', '<?php echo 1; ?>'),
        ])->assertRedirect(route('inbox-settings.index'))->assertSessionHas('error');

        $this->assertSame(0, IncomingEmail::query()->count());
        $this->assertNotNull($source->fresh()->last_error);
    }

    public function test_only_xlsx_files_and_a_file_are_accepted(): void
    {
        $this->manualSource($this->own);

        $this->signIn($this->coordinator)->post(route('sheet-sources.upload', $this->own), [
            'file' => UploadedFile::fake()->createWithContent('datos.csv', "Folio,Asunto\nA-1,X"),
        ])->assertInvalid('file');

        $this->signIn($this->coordinator)->post(route('sheet-sources.upload', $this->own), [])->assertInvalid('file');
        $this->assertSame(0, IncomingEmail::query()->count());
    }

    public function test_reception_date_column_sets_the_real_date_and_keeps_the_sheet_order(): void
    {
        $this->manualSource($this->own)->forceFill(['received_at_column' => 'Fecha'])->save();

        $this->signIn($this->coordinator)->post(route('sheet-sources.upload', $this->own), [
            'file' => $this->file([
                ['Folio', 'Asunto', 'Fecha'],
                ['A-1', 'Primero', 'martes 11 de agosto 2026, 1:11 p. m.'],
                ['A-2', 'Segundo mismo minuto', 'martes 11 de agosto 2026, 1:11 p. m.'],
                ['A-3', 'Tercero', 'viernes 6 de noviembre 2026, 10:09 a. m.'],
            ]),
        ])->assertSessionHas('notice');

        $first = IncomingEmail::query()->where('subject', 'Primero')->firstOrFail();
        $second = IncomingEmail::query()->where('subject', 'Segundo mismo minuto')->firstOrFail();
        // 1:11 p. m. en Cancún (UTC-5) = 18:11 UTC; el segundo del mismo minuto conserva el orden de la hoja.
        $this->assertSame('2026-08-11 18:11:00', $first->received_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-11 18:11:01', $second->received_at->utc()->format('Y-m-d H:i:s'));

        $order = IncomingEmail::query()->orderByDesc('received_at')->orderByDesc('id')->pluck('subject')->all();
        $this->assertSame(['Tercero', 'Segundo mismo minuto', 'Primero'], $order);
    }

    public function test_rows_without_subject_and_date_are_skipped_but_rows_with_a_date_are_kept(): void
    {
        $this->manualSource($this->own)->forceFill(['received_at_column' => 'Fecha'])->save();

        $this->signIn($this->coordinator)->post(route('sheet-sources.upload', $this->own), [
            'file' => $this->file([
                ['Folio', 'Asunto', 'Fecha', 'Lista'],
                ['', '', '', 'NEREYDA'],
                ['', '', '', 'MARIN'],
                ['A-1', '', 'lunes 3 de agosto 2026, 9:00 a. m.', ''],
                ['A-2', 'Con asunto', 'lunes 3 de agosto 2026, 9:05 a. m.', ''],
            ]),
        ]);

        $this->assertSame(2, IncomingEmail::query()->count());
    }

    public function test_without_a_date_column_the_import_time_is_used(): void
    {
        $this->manualSource($this->own);

        $this->signIn($this->coordinator)->post(route('sheet-sources.upload', $this->own), [
            'file' => $this->file([['Folio', 'Asunto', 'Fecha'], ['A-1', 'X', 'martes 11 de agosto 2026, 1:11 p. m.']]),
        ]);

        $this->assertTrue(IncomingEmail::query()->firstOrFail()->received_at->isToday());
    }

    public function test_composite_key_ignores_edits_to_other_columns_and_keeps_same_minute_rows_apart(): void
    {
        $this->manualSource($this->own)->forceFill(['key_column' => 'Fecha | Correo | Asunto', 'received_at_column' => 'Fecha'])->save();

        $header = ['Fecha', 'Correo', 'Asunto', 'Estado'];
        $first = ['martes 11 de agosto 2026, 1:11 p. m.', 'ana@example.com', 'Oficio uno', ''];
        $sameMinute = ['martes 11 de agosto 2026, 1:11 p. m.', 'beto@example.com', 'Oficio dos', ''];

        $this->signIn($this->coordinator)->post(route('sheet-sources.upload', $this->own), ['file' => $this->file([$header, $first, $sameMinute])]);
        $this->assertSame(2, IncomingEmail::query()->count());

        // Alguien trabaja el Excel (cambia "Estado") y se agrega una fila nueva: nada se duplica ni se reimporta.
        $first[3] = 'ATENDIDO';
        $new = ['miércoles 12 de agosto 2026, 9:00 a. m.', 'ana@example.com', 'Oficio tres', ''];
        $this->signIn($this->coordinator)->post(route('sheet-sources.upload', $this->own), ['file' => $this->file([$header, $first, $sameMinute, $new])]);

        $this->assertSame(3, IncomingEmail::query()->count());
        $this->assertSame(1, IncomingEmail::query()->where('subject', 'Oficio uno')->count());
    }

    public function test_without_a_key_an_edited_row_is_imported_again(): void
    {
        $this->manualSource($this->own)->forceFill(['key_column' => null])->save();

        $header = ['Fecha', 'Asunto', 'Estado'];
        $row = ['hoy', 'Oficio uno', ''];

        $this->signIn($this->coordinator)->post(route('sheet-sources.upload', $this->own), ['file' => $this->file([$header, $row])]);
        $row[2] = 'ATENDIDO';
        $this->signIn($this->coordinator)->post(route('sheet-sources.upload', $this->own), ['file' => $this->file([$header, $row])]);

        // Es la razón de recomendar una clave: sin ella, la huella es toda la fila.
        $this->assertSame(2, IncomingEmail::query()->count());
    }

    public function test_key_columns_are_split_on_pipes_and_trimmed(): void
    {
        $this->assertSame(['Fecha', 'Correo', 'Asunto'], SheetInboxImporter::keyColumns(' Fecha | Correo |Asunto| '));
        $this->assertSame(['Folio'], SheetInboxImporter::keyColumns('Folio'));
        $this->assertSame([], SheetInboxImporter::keyColumns(null));
        $this->assertSame([], SheetInboxImporter::keyColumns(' | '));
    }

    public function test_sheet_can_be_saved_without_a_share_url_for_manual_mode(): void
    {
        $this->signIn($this->coordinator)->put(route('sheet-sources.update', $this->own), [
            'name' => 'Seguimiento',
            'share_url' => '',
            'header_row' => 1,
            'subject_column' => 'Asunto',
            'active' => 1,
        ])->assertRedirect(route('inbox-settings.index'));

        $this->assertSame('', SpreadsheetSource::query()->where('coordination_id', $this->own->id)->value('share_url'));
    }

    public function test_inbox_settings_shows_the_upload_form_only_when_a_sheet_exists(): void
    {
        $this->signIn($this->coordinator)->get(route('inbox-settings.index'))
            ->assertOk()->assertDontSee(route('sheet-sources.upload', $this->own));

        $this->manualSource($this->own);

        $this->signIn($this->coordinator)->get(route('inbox-settings.index'))
            ->assertOk()->assertSee(route('sheet-sources.upload', $this->own))->assertSee('enctype="multipart/form-data"', false);
    }
}
