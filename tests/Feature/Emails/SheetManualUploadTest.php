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
