<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * Comentarios y adjuntos de actividades: mismas reglas de seguridad que en tickets (servicios compartidos).
 */
class ActivityCommentAndAttachmentTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private Activity $activity;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->buildScenario();
        $this->activity = $this->makeActivity($this->teamA, [], $this->empA1);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function realFile(string $clientName, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function upload(User $actor, UploadedFile $file, ?Activity $activity = null): TestResponse
    {
        return $this->signIn($actor)->post('/activities/'.($activity ?? $this->activity)->id.'/attachments', ['file' => $file]);
    }

    private function comment(User $actor, string $body, ?Activity $activity = null): TestResponse
    {
        return $this->signIn($actor)->post('/activities/'.($activity ?? $this->activity)->id.'/comments', ['body' => $body]);
    }

    // --- Comentarios ---------------------------------------------------------------------------------------------------------------

    public function test_assigned_employee_and_managers_comment_and_it_is_audited(): void
    {
        $this->comment($this->empA1, '  Avance del 50 %  ')->assertRedirect(route('activities.show', $this->activity).'#comentarios')->assertSessionHas('status', 'comment-added');
        $this->comment($this->coordA, 'Enterado');
        $this->comment($this->jefe, 'Gracias');

        $comments = $this->activity->comments()->orderBy('id')->get();
        $this->assertSame(['Avance del 50 %', 'Enterado', 'Gracias'], $comments->pluck('body')->all());
        $this->assertSame([$this->empA1->id, $this->coordA->id, $this->jefe->id], $comments->pluck('user_id')->all());
        $this->assertSame('activity', $comments->first()->commentable_type);

        $entry = ActivityLogEntry::query()->where('subject_type', 'activity')->where('event', 'comment_added')->orderBy('id')->firstOrFail();
        $this->assertSame($this->empA1->id, $entry->causer_id);
        $this->assertSame('activities', $entry->log_name);
    }

    public function test_comment_is_validated_and_rendered_escaped(): void
    {
        $this->comment($this->empA1, '')->assertInvalid(['body']);
        $this->comment($this->empA1, str_repeat('a', 2001))->assertInvalid(['body']);

        $payload = '<script>alert("xss")</script> {{ 7*7 }}';
        $this->comment($this->empA1, $payload)->assertSessionHasNoErrors();

        $this->signIn($this->coordA)->get("/activities/{$this->activity->id}")
            ->assertOk()
            ->assertDontSee('<script>alert("xss")</script>', false)
            ->assertSee('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false);
    }

    public function test_people_outside_the_scope_cannot_comment(): void
    {
        $this->comment($this->empA2, 'intruso')->assertNotFound();
        $this->comment($this->coordB, 'intruso')->assertNotFound();
        $this->comment($this->empB1, 'intruso')->assertNotFound();

        $this->assertSame(0, Comment::query()->count());
    }

    // --- Adjuntos: subida ----------------------------------------------------------------------------------------------------------

    public function test_valid_pdf_is_stored_privately_under_the_activity_directory_and_audited(): void
    {
        $this->upload($this->empA1, $this->realFile('Evidencia.pdf', self::PDF))->assertRedirect(route('activities.show', $this->activity).'#adjuntos')->assertSessionHas('status', 'attachment-added');

        $attachment = Attachment::query()->firstOrFail();
        $this->assertSame('activity', $attachment->attachable_type);
        $this->assertSame($this->activity->id, $attachment->attachable_id);
        $this->assertSame('Evidencia.pdf', $attachment->original_name);
        $this->assertSame('application/pdf', $attachment->mime);
        $this->assertMatchesRegularExpression('#^activities/'.$this->activity->id.'/[A-Za-z0-9]{40}\.pdf$#', $attachment->path);
        Storage::disk('local')->assertExists($attachment->path);
        Storage::disk('public')->assertMissing($attachment->path);

        $entry = ActivityLogEntry::query()->where('subject_type', 'activity')->where('event', 'attachment_added')->firstOrFail();
        $this->assertSame($attachment->id, $entry->properties['attachment_id']);
        $this->assertSame($this->activity->folio, $entry->properties['folio']);
    }

    public function test_activity_and_ticket_attachments_never_share_a_directory(): void
    {
        $ticket = $this->makeTicket($this->teamA, $this->empA1);
        $this->assertSame($this->activity->id, $ticket->id, 'ids iguales en tablas distintas: el caso que colisionaría');

        $this->upload($this->empA1, $this->realFile('a.pdf', self::PDF));
        $this->signIn($this->empA1)->post("/tickets/{$ticket->id}/attachments", ['file' => $this->realFile('t.pdf', self::PDF)]);

        $paths = Attachment::query()->orderBy('id')->pluck('path')->all();
        $this->assertStringStartsWith("activities/{$this->activity->id}/", $paths[0]);
        $this->assertStringStartsWith("tickets/{$ticket->id}/", $paths[1]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forbiddenFiles(): array
    {
        return [
            'php script' => ['shell.php', '<?php system($_GET["c"]);'],
            'html' => ['page.html', '<html><script>alert(1)</script></html>'],
            'svg with script' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'executable' => ['setup.exe', "MZ\x90\x00\x03\x00\x00\x00"],
            'double extension' => ['invoice.pdf.php', self::PDF],
            'php renamed to pdf' => ['evil.pdf', '<?php system($_GET["c"]); ?>'],
            'pdf renamed to png' => ['doc.png', self::PDF],
            'fake docx' => ['fake.docx', '<?php echo 1;'],
        ];
    }

    #[DataProvider('forbiddenFiles')]
    public function test_forbidden_or_disguised_files_are_rejected_and_nothing_is_stored(string $name, string $content): void
    {
        $this->upload($this->empA1, $this->realFile($name, $content))->assertInvalid('file');

        $this->assertSame(0, Attachment::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_oversized_files_are_rejected(): void
    {
        config(['tickets.attachments.max_kilobytes' => 1]);

        $this->upload($this->empA1, $this->realFile('big.txt', str_repeat('a', 2048)))->assertInvalid('file');
        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_the_number_of_attachments_per_activity_is_capped(): void
    {
        config(['tickets.attachments.max_per_ticket' => 1]);

        $this->upload($this->empA1, $this->realFile('a.txt', 'uno'));
        $this->from('/x')->upload($this->empA1, $this->realFile('b.txt', 'dos'))->assertSessionHas('error');

        $this->assertSame(1, $this->activity->attachments()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_people_outside_the_scope_cannot_upload(): void
    {
        $this->upload($this->empA2, $this->realFile('a.txt', 'x'))->assertNotFound();
        $this->upload($this->coordB, $this->realFile('a.txt', 'x'))->assertNotFound();

        $this->assertSame(0, Attachment::query()->count());
    }

    // --- Adjuntos: descarga y borrado ------------------------------------------------------------------------------------------------

    private function storedAttachment(?User $author = null): Attachment
    {
        $author ??= $this->empA1;
        $attachment = Attachment::factory()->for($this->activity, 'attachable')->create([
            'user_id' => $author->id,
            'original_name' => 'acta.pdf',
            'path' => 'activities/'.$this->activity->id.'/'.Str::random(40).'.pdf',
        ]);
        Storage::disk('local')->put($attachment->path, self::PDF);

        return $attachment;
    }

    public function test_download_goes_through_the_controller_with_safe_headers_for_people_who_can_see_the_activity(): void
    {
        $attachment = $this->storedAttachment();

        foreach ([$this->empA1, $this->coordA, $this->jefe] as $viewer) {
            $response = $this->signIn($viewer)->get(route('attachments.download', $attachment))->assertOk();
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }
    }

    public function test_download_is_a_404_for_people_outside_the_scope_and_guests_go_to_login(): void
    {
        $attachment = $this->storedAttachment();

        foreach ([$this->empA2, $this->empB1, $this->coordB] as $outsider) {
            $this->signIn($outsider)->get(route('attachments.download', $attachment))->assertNotFound();
        }

        auth()->logout();
        $this->get(route('attachments.download', $attachment))->assertRedirect('/login');
    }

    public function test_a_subtask_assignee_who_can_open_the_activity_can_download_its_files(): void
    {
        $attachment = $this->storedAttachment();
        $this->makeSubtask($this->activity, 'x', false, $this->empA2);

        $this->signIn($this->empA2)->get(route('attachments.download', $attachment))->assertOk();
    }

    public function test_author_or_manager_deletes_and_the_file_leaves_the_disk(): void
    {
        $attachment = $this->storedAttachment($this->empA1);
        $other = $this->storedAttachment($this->coordA);

        $this->signIn($this->empA1)->delete(route('attachments.destroy', $attachment))->assertRedirect(route('activities.show', $this->activity).'#adjuntos')->assertSessionHas('status', 'attachment-deleted');
        $this->assertNull(Attachment::query()->find($attachment->id));

        // Un empleado no borra archivos de otros; el coordinador del equipo sí.
        $this->signIn($this->empA1)->delete(route('attachments.destroy', $other))->assertForbidden();
        $this->signIn($this->coordA)->delete(route('attachments.destroy', $other))->assertRedirect();
        $this->assertNull(Attachment::query()->find($other->id));

        $this->assertNotNull(ActivityLogEntry::query()->where('subject_type', 'activity')->where('event', 'attachment_removed')->first());
    }

    public function test_deleting_is_a_404_outside_the_scope(): void
    {
        $attachment = $this->storedAttachment();

        $this->signIn($this->coordB)->delete(route('attachments.destroy', $attachment))->assertNotFound();
        $this->assertNotNull(Attachment::query()->find($attachment->id));
    }
}
