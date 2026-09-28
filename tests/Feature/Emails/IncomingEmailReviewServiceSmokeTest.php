<?php

declare(strict_types=1);

namespace Tests\Feature\Emails;

use App\Enums\IncomingEmailStatus;
use App\Enums\Priority;
use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\IncomingEmail;
use App\Models\IncomingEmailAttachment;
use App\Services\IncomingEmailReviewService;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * Prueba desechable de aislamiento (sin controlador/ruta todavia): comprueba de punta a punta que
 * IncomingEmailReviewService::discard() y ::convert() funcionan contra un fixture en memoria, ya que la
 * capa HTTP que los ejercitara de verdad (slice paralelo) aun no existe. Se elimina una vez que
 * IncomingEmailConversionTest/IncomingEmailDiscardTest cubran lo mismo por HTTP.
 */
class IncomingEmailReviewServiceSmokeTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        Storage::fake('local');
    }

    private function makeIncomingEmail(string $messageId): IncomingEmail
    {
        $email = new IncomingEmail([
            'message_id' => $messageId,
            'from_email' => 'solicitante@example.com',
            'from_name' => 'Solicitante Externo',
            'subject' => 'Necesito ayuda con una falla',
            'body' => 'Descripción del problema reportado por correo.',
            'received_at' => now(),
        ]);
        $email->forceFill(['status' => IncomingEmailStatus::PendingReview])->save();

        return $email;
    }

    private function attachRealFile(IncomingEmail $email): IncomingEmailAttachment
    {
        $path = "incoming-emails/{$email->id}/original.txt";
        Storage::disk('local')->put($path, 'contenido de prueba');

        return $email->attachments()->create([
            'original_name' => 'evidencia.txt',
            'path' => $path,
            'mime' => 'text/plain',
            'size' => 20,
        ]);
    }

    public function test_discard_marks_the_email_with_reason_and_reviewer_and_audits_it(): void
    {
        $email = $this->makeIncomingEmail('msg-discard-1');

        $result = app(IncomingEmailReviewService::class)->discard($this->jefe, $email, 'No es una tarea real');

        $this->assertSame(IncomingEmailStatus::Discarded, $result->status);
        $this->assertSame('No es una tarea real', $result->discard_reason);
        $this->assertSame($this->jefe->id, $result->reviewed_by);
        $this->assertNotNull($result->reviewed_at);

        $this->assertNotNull(
            ActivityLogEntry::query()->where('log_name', 'emails')->where('event', 'discarded')->first()
        );
    }

    public function test_discard_rejects_an_already_reviewed_email(): void
    {
        $email = $this->makeIncomingEmail('msg-discard-2');
        app(IncomingEmailReviewService::class)->discard($this->jefe, $email, 'Motivo inicial');

        $this->expectException(BusinessRuleException::class);

        app(IncomingEmailReviewService::class)->discard($this->jefe, $email->fresh(), 'Segundo intento');
    }

    public function test_convert_creates_an_activity_copies_attachments_and_marks_the_email_converted(): void
    {
        $email = $this->makeIncomingEmail('msg-convert-1');
        $sourceAttachment = $this->attachRealFile($email);

        $activityData = [
            'title' => $email->subject,
            'description' => $email->body,
            'priority' => Priority::Medium->value,
            'due_date' => now()->addDays(5)->toDateString(),
            'responsible_id' => $this->empA1->id,
        ];

        $activity = app(IncomingEmailReviewService::class)->convert($this->coordA, $email, $activityData);

        $this->assertInstanceOf(Activity::class, $activity);
        $this->assertNotNull($activity->folio);
        $this->assertSame($this->teamA->id, $activity->team_id);

        $email->refresh();
        $this->assertSame(IncomingEmailStatus::Converted, $email->status);
        $this->assertSame($activity->id, $email->activity_id);
        $this->assertSame($this->coordA->id, $email->reviewed_by);

        $copied = $activity->attachments()->firstOrFail();
        $this->assertInstanceOf(Attachment::class, $copied);
        $this->assertSame('evidencia.txt', $copied->original_name);
        $this->assertNotSame($sourceAttachment->path, $copied->path);

        // El original permanece intacto: nunca se mueve, se copia.
        Storage::disk('local')->assertExists($sourceAttachment->path);
        Storage::disk('local')->assertExists($copied->path);

        $this->assertNotNull(
            ActivityLogEntry::query()->where('log_name', 'emails')->where('event', 'converted')->first()
        );
    }

    public function test_convert_rejects_an_already_reviewed_email(): void
    {
        $email = $this->makeIncomingEmail('msg-convert-2');
        app(IncomingEmailReviewService::class)->discard($this->jefe, $email, 'No aplica');

        $this->expectException(BusinessRuleException::class);

        app(IncomingEmailReviewService::class)->convert($this->coordA, $email->fresh(), [
            'title' => 'x',
            'description' => 'y',
            'priority' => Priority::Low->value,
            'responsible_id' => $this->empA1->id,
        ]);
    }
}
