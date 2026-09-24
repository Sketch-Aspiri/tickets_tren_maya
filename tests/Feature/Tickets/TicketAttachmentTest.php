<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\UserStatus;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use App\Support\AttachmentInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsTicketScenario;
use Tests\DatabaseTestCase;
use ZipArchive;

class TicketAttachmentTest extends DatabaseTestCase
{
    use BuildsTicketScenario;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->buildScenario();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * Archivo REAL en disco (no el falso de Laravel, que deduce el MIME del nombre): el MIME se detecta por contenido.
     */
    private function realFile(string $clientName, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function officeZip(string $marker, bool $withContentTypes = true): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $this->temporaryFiles[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($withContentTypes) {
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
        }
        $zip->addFromString($marker, '<x/>');
        $zip->close();

        return (string) file_get_contents($path);
    }

    private function upload(User $actor, Ticket $ticket, UploadedFile $file): TestResponse
    {
        return $this->signIn($actor)->post("/tickets/{$ticket->id}/attachments", ['file' => $file]);
    }

    private function bagTicket(): Ticket
    {
        return $this->makeTicket($this->teamA, $this->empA1);
    }

    // --- Subida valida ------------------------------------------------------------------------------

    public function test_valid_pdf_is_stored_privately_with_a_random_name_and_audited(): void
    {
        $ticket = $this->bagTicket();

        $this->upload($this->empA1, $ticket, $this->realFile('Reporte Mensual.pdf', self::PDF))
            ->assertRedirect()
            ->assertSessionHas('status', 'attachment-added');

        $attachment = Attachment::query()->firstOrFail();
        $this->assertSame('Reporte Mensual.pdf', $attachment->original_name);
        $this->assertSame('application/pdf', $attachment->mime);
        $this->assertSame(strlen(self::PDF), $attachment->size);
        $this->assertSame($this->empA1->id, $attachment->user_id);
        $this->assertSame($ticket->id, $attachment->attachable_id);
        $this->assertSame('ticket', $attachment->attachable_type);

        // Nombre aleatorio elegido por el servidor, dentro del directorio privado del ticket.
        $this->assertMatchesRegularExpression('#^tickets/'.$ticket->id.'/[A-Za-z0-9]{40}\.pdf$#', $attachment->path);
        $this->assertStringNotContainsString('Reporte', $attachment->path);
        Storage::disk('local')->assertExists($attachment->path);
        Storage::disk('public')->assertMissing($attachment->path);

        $activity = Activity::query()->where('subject_type', 'ticket')->where('event', 'attachment_added')->firstOrFail();
        $this->assertSame($this->empA1->id, $activity->causer_id);
        $this->assertSame($attachment->id, $activity->properties['attachment_id']);
    }

    public function test_all_whitelisted_types_are_accepted(): void
    {
        $ticket = $this->bagTicket();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $jpg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAAP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');

        $files = [
            $this->realFile('a.pdf', self::PDF),
            $this->realFile('b.png', $png),
            $this->realFile('c.jpg', $jpg),
            $this->realFile('d.jpeg', $jpg),
            $this->realFile('e.txt', "texto plano\nlinea 2\n"),
            $this->realFile('f.docx', $this->officeZip('word/document.xml')),
            $this->realFile('g.xlsx', $this->officeZip('xl/workbook.xml')),
        ];

        foreach ($files as $file) {
            $this->upload($this->empA1, $ticket, $file)->assertSessionHasNoErrors();
        }

        $this->assertSame(7, $ticket->attachments()->count());
    }

    // --- Tipos prohibidos ------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forbiddenFiles(): array
    {
        return [
            'php script' => ['shell.php', '<?php system($_GET["c"]);'],
            'html' => ['page.html', '<html><script>alert(1)</script></html>'],
            'svg with script' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'javascript' => ['app.js', 'alert(1)'],
            'executable' => ['setup.exe', "MZ\x90\x00\x03\x00\x00\x00"],
            'double extension php last' => ['invoice.pdf.php', self::PDF],
            'no extension' => ['README', 'texto'],
            'phtml' => ['x.phtml', '<?php echo 1;'],
            'htaccess' => ['.htaccess', 'php_flag engine on'],
            'uppercase forbidden' => ['SHELL.PHP', '<?php echo 1;'],
        ];
    }

    #[DataProvider('forbiddenFiles')]
    public function test_forbidden_types_are_rejected_and_nothing_is_stored(string $name, string $content): void
    {
        $ticket = $this->bagTicket();

        $this->upload($this->empA1, $ticket, $this->realFile($name, $content))->assertInvalid('file');

        $this->assertSame(0, Attachment::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_php_file_renamed_to_pdf_is_rejected_by_its_real_mime(): void
    {
        $ticket = $this->bagTicket();

        $this->upload($this->empA1, $ticket, $this->realFile('evil.pdf', '<?php system($_GET["c"]); ?>'))->assertInvalid('file');
        $this->upload($this->empA1, $ticket, $this->realFile('evil.png', '<?php echo 1;'))->assertInvalid('file');
        $this->upload($this->empA1, $ticket, $this->realFile('evil.docx', '<?php echo 1;'))->assertInvalid('file');

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_a_real_pdf_with_a_png_extension_is_rejected(): void
    {
        $this->upload($this->empA1, $this->bagTicket(), $this->realFile('doc.png', self::PDF))->assertInvalid('file');
    }

    public function test_a_generic_zip_named_docx_or_xlsx_needs_office_structure(): void
    {
        $ticket = $this->bagTicket();
        $randomZip = $this->officeZip('payload.exe');
        $missingContentTypes = $this->officeZip('word/document.xml', withContentTypes: false);

        $this->upload($this->empA1, $ticket, $this->realFile('fake.docx', $randomZip))->assertInvalid('file');
        $this->upload($this->empA1, $ticket, $this->realFile('fake.xlsx', $randomZip))->assertInvalid('file');
        $this->upload($this->empA1, $ticket, $this->realFile('fake2.docx', $missingContentTypes))->assertInvalid('file');
        $this->upload($this->empA1, $ticket, $this->realFile('mismatch.xlsx', $this->officeZip('word/document.xml')))->assertInvalid('file');

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_client_declared_mime_is_ignored(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, '<?php echo 1;');
        $this->temporaryFiles[] = $path;
        // El cliente MIENTE: declara application/pdf para un script.
        $liar = new UploadedFile($path, 'report.pdf', 'application/pdf', null, true);

        $this->upload($this->empA1, $this->bagTicket(), $liar)->assertInvalid('file');
    }

    // --- Tamano y cantidad ------------------------------------------------------------------------------

    public function test_file_over_the_configured_size_is_rejected_and_the_limit_is_configurable(): void
    {
        $ticket = $this->bagTicket();
        $twoHundredKb = self::PDF.str_repeat('A', 200 * 1024);

        $this->assertSame(10240, (int) config('tickets.attachments.max_kilobytes'), 'por defecto 10 MB');

        config(['tickets.attachments.max_kilobytes' => 100]);
        $this->upload($this->empA1, $ticket, $this->realFile('grande.pdf', $twoHundredKb))->assertInvalid('file');
        $this->assertSame(0, Attachment::query()->count());

        config(['tickets.attachments.max_kilobytes' => 300]);
        $this->upload($this->empA1, $ticket, $this->realFile('grande.pdf', $twoHundredKb))->assertSessionHasNoErrors();
        $this->assertSame(1, Attachment::query()->count());
    }

    public function test_a_file_is_required(): void
    {
        $this->signIn($this->empA1)->post("/tickets/{$this->bagTicket()->id}/attachments", [])->assertInvalid('file');
        $this->signIn($this->empA1)->post("/tickets/{$this->bagTicket()->id}/attachments", ['file' => 'texto'])->assertInvalid('file');
    }

    public function test_attachments_per_ticket_are_capped_and_the_extra_file_is_not_left_on_disk(): void
    {
        config(['tickets.attachments.max_per_ticket' => 2]);
        $ticket = $this->bagTicket();

        $this->upload($this->empA1, $ticket, $this->realFile('1.pdf', self::PDF))->assertSessionHasNoErrors();
        $this->upload($this->empA1, $ticket, $this->realFile('2.pdf', self::PDF))->assertSessionHasNoErrors();
        $this->upload($this->empA1, $ticket, $this->realFile('3.pdf', self::PDF))
            ->assertSessionHas('error', __('tickets.errors.too_many_attachments', ['max' => 2]));

        $this->assertSame(2, Attachment::query()->count());
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    // --- Nombres maliciosos ---------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function maliciousNames(): array
    {
        return [
            'unix traversal' => ['../../../etc/passwd.pdf', 'passwd.pdf'],
            'windows traversal' => ['..\\..\\windows\\evil.pdf', 'evil.pdf'],
            'absolute path' => ['/var/www/public/x.pdf', 'x.pdf'],
            'markup' => ['<img src=x onerror=alert(1)>.pdf', 'img srcx onerroralert(1).pdf'],
            'control chars' => ["a\x00b\r\nc.pdf", 'abc.pdf'],
            'leading dots' => ['...hidden.pdf', 'hidden.pdf'],
        ];
    }

    #[DataProvider('maliciousNames')]
    public function test_malicious_client_file_names_are_sanitized_and_never_touch_the_storage_path(string $clientName, string $expected): void
    {
        $ticket = $this->bagTicket();

        $this->upload($this->empA1, $ticket, $this->realFile($clientName, self::PDF))->assertSessionHasNoErrors();

        $attachment = Attachment::query()->firstOrFail();
        $this->assertSame($expected, $attachment->original_name);
        $this->assertMatchesRegularExpression('#^tickets/'.$ticket->id.'/[A-Za-z0-9]{40}\.pdf$#', $attachment->path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_sanitize_name_falls_back_and_truncates(): void
    {
        $inspector = new AttachmentInspector;

        $this->assertSame('archivo', $inspector->sanitizeName('...'));
        $this->assertSame('archivo', $inspector->sanitizeName('<>'));
        $this->assertSame(100, mb_strlen($inspector->sanitizeName(str_repeat('a', 300).'.pdf')));
        $this->assertSame('Informe año.pdf', $inspector->sanitizeName('Informe   año.pdf'));
    }

    // --- Permisos de subida / IDOR ---------------------------------------------------------------------------------

    public function test_only_users_who_can_see_the_ticket_may_attach(): void
    {
        $ticket = $this->bagTicket();
        $assignedToPeer = Ticket::factory()->forTeam($this->teamA)->assignedTo($this->empA2)->create();

        $this->upload($this->coordA, $ticket, $this->realFile('a.pdf', self::PDF))->assertSessionHasNoErrors();
        $this->upload($this->jefe, $ticket, $this->realFile('b.pdf', self::PDF))->assertSessionHasNoErrors();
        $this->upload($this->coordB, $ticket, $this->realFile('c.pdf', self::PDF))->assertNotFound();
        $this->upload($this->empB1, $ticket, $this->realFile('d.pdf', self::PDF))->assertNotFound();
        $this->upload($this->empA1, $assignedToPeer, $this->realFile('e.pdf', self::PDF))->assertNotFound();

        $this->assertSame(2, Attachment::query()->count());
    }

    public function test_uploads_are_rate_limited(): void
    {
        config(['tickets.rate_limits.ticket_upload_per_minute' => 2]);
        $ticket = $this->bagTicket();

        $this->upload($this->empA1, $ticket, $this->realFile('1.pdf', self::PDF))->assertRedirect();
        $this->upload($this->empA1, $ticket, $this->realFile('2.pdf', self::PDF))->assertRedirect();
        $this->upload($this->empA1, $ticket, $this->realFile('3.pdf', self::PDF))->assertStatus(429);
    }

    // --- Descarga ------------------------------------------------------------------------------------------------------------

    private function storedAttachment(Ticket $ticket, string $content = self::PDF): Attachment
    {
        $this->upload($this->empA1, $ticket, $this->realFile('Informe final.pdf', $content))->assertSessionHasNoErrors();

        return Attachment::query()->latest('id')->firstOrFail();
    }

    public function test_authorized_users_download_with_safe_headers(): void
    {
        $attachment = $this->storedAttachment($this->bagTicket());

        foreach ([$this->empA1, $this->empA2, $this->coordA, $this->jefe] as $actor) {
            $response = $this->signIn($actor)->get(route('attachments.download', $attachment));

            $response->assertOk();
            $this->assertSame(self::PDF, $response->streamedContent());
            $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('Informe final.pdf', (string) $response->headers->get('Content-Disposition'));
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }
    }

    public function test_download_is_never_inline_even_for_images_and_text(): void
    {
        $ticket = $this->bagTicket();
        $this->upload($this->empA1, $ticket, $this->realFile('nota.txt', "hola\n"))->assertSessionHasNoErrors();
        $attachment = Attachment::query()->firstOrFail();

        $response = $this->signIn($this->empA1)->get(route('attachments.download', $attachment));

        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_download_without_permission_is_a_404_that_does_not_leak_existence(): void
    {
        $ticket = $this->bagTicket();
        $attachment = $this->storedAttachment($ticket);
        $peerTicket = Ticket::factory()->forTeam($this->teamA)->assignedTo($this->empA2)->create();
        $peerAttachment = Attachment::factory()->for($peerTicket, 'attachable')->create();
        Storage::disk('local')->put($peerAttachment->path, self::PDF);

        $this->signIn($this->coordB)->get(route('attachments.download', $attachment))->assertNotFound();
        $this->signIn($this->empB1)->get(route('attachments.download', $attachment))->assertNotFound();
        $this->signIn($this->empA1)->get(route('attachments.download', $peerAttachment))->assertNotFound();
    }

    public function test_download_of_a_soft_deleted_tickets_attachment_is_404(): void
    {
        $ticket = $this->bagTicket();
        $attachment = $this->storedAttachment($ticket);
        $ticket->delete();

        $this->signIn($this->jefe)->get(route('attachments.download', $attachment))->assertNotFound();
    }

    public function test_inactive_users_cannot_download(): void
    {
        $attachment = $this->storedAttachment($this->bagTicket());
        $this->empA2->forceFill(['status' => UserStatus::Inactive])->save();

        $this->signIn($this->empA2)->get(route('attachments.download', $attachment))->assertRedirect(route('account.status'));
    }

    public function test_missing_file_on_disk_is_404_not_500(): void
    {
        $attachment = $this->storedAttachment($this->bagTicket());
        Storage::disk('local')->delete($attachment->path);

        $this->signIn($this->jefe)->get(route('attachments.download', $attachment))->assertNotFound();
    }

    public function test_downloads_are_rate_limited(): void
    {
        config(['tickets.rate_limits.attachment_download_per_minute' => 2]);
        $attachment = $this->storedAttachment($this->bagTicket());

        $this->signIn($this->empA1)->get(route('attachments.download', $attachment))->assertOk();
        $this->signIn($this->empA1)->get(route('attachments.download', $attachment))->assertOk();
        $this->signIn($this->empA1)->get(route('attachments.download', $attachment))->assertStatus(429);
    }

    public function test_files_are_never_reachable_from_a_public_url(): void
    {
        $attachment = $this->storedAttachment($this->bagTicket());

        $this->assertSame('local', config('tickets.attachments.disk'));
        $this->assertFalse(config('filesystems.disks.local.visibility') === 'public');
        $this->assertNotSame('public', config('tickets.attachments.disk'));
        // La ruta /storage/* del disco local solo sirve URLs firmadas (nadie las genera): sin firma no entrega nada.
        $this->assertContains($this->get('/storage/'.$attachment->path)->getStatusCode(), [403, 404]);
        $this->get('/'.$attachment->path)->assertNotFound();
    }

    public function test_attachment_serialization_never_exposes_the_storage_path(): void
    {
        $attachment = $this->storedAttachment($this->bagTicket());

        $this->assertArrayNotHasKey('path', $attachment->toArray());
    }

    // --- Borrar -----------------------------------------------------------------------------------------------------------------

    public function test_author_deletes_own_attachment_and_the_file_is_removed_from_disk_with_audit(): void
    {
        $ticket = $this->bagTicket();
        $attachment = $this->storedAttachment($ticket);
        $path = $attachment->path;

        $this->signIn($this->empA1)->delete(route('attachments.destroy', $attachment))
            ->assertRedirect()
            ->assertSessionHas('status', 'attachment-deleted');

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($path);
        $activity = Activity::query()->where('subject_type', 'ticket')->where('event', 'attachment_removed')->firstOrFail();
        $this->assertSame($this->empA1->id, $activity->causer_id);
        $this->assertSame('Informe final.pdf', $activity->properties['original_name']);
    }

    public function test_coordinator_of_the_team_and_jefe_can_delete_others_attachments(): void
    {
        $ticket = $this->bagTicket();

        foreach ([$this->coordA, $this->jefe] as $actor) {
            $attachment = $this->storedAttachment($ticket);
            $this->signIn($actor)->delete(route('attachments.destroy', $attachment))->assertSessionHas('status', 'attachment-deleted');
            $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        }
    }

    public function test_other_employees_cannot_delete_and_other_teams_get_404(): void
    {
        $attachment = $this->storedAttachment($this->bagTicket());

        $this->signIn($this->empA2)->delete(route('attachments.destroy', $attachment))->assertForbidden();
        $this->signIn($this->coordB)->delete(route('attachments.destroy', $attachment))->assertNotFound();
        $this->signIn($this->empB1)->delete(route('attachments.destroy', $attachment))->assertNotFound();

        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertExists($attachment->path);
    }

    // --- Vista -------------------------------------------------------------------------------------------------------------------------

    public function test_show_lists_attachments_with_escaped_names_and_a_download_link_not_a_path(): void
    {
        $ticket = $this->bagTicket();
        $attachment = Attachment::factory()->for($ticket, 'attachable')->create(['original_name' => '<b>x</b>.pdf', 'user_id' => $this->empA1->id]);

        $html = $this->signIn($this->empA1)->get("/tickets/{$ticket->id}")->assertOk()->getContent();

        $this->assertStringContainsString(route('attachments.download', $attachment), $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;.pdf', $html);
        $this->assertStringNotContainsString($attachment->path, $html);
    }
}
