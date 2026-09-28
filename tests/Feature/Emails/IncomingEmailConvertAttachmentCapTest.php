<?php

declare(strict_types=1);

namespace Tests\Feature\Emails;

use App\Enums\IncomingEmailStatus;
use App\Enums\Priority;
use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\IncomingEmail;
use App\Services\IncomingEmailReviewService;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * IncomingEmailReviewService::convert(): el tope de adjuntos por actividad (`tickets.attachments.max_per_ticket`,
 * el MISMO que usa AttachmentService::store()/adopt()) se comprueba ANTES de copiar ningun adjunto, para
 * que un correo con mas adjuntos de los que la actividad puede recibir falle de forma limpia SIN dejar
 * archivos huerfanos en disco (adjuntos ya copiados de un bucle que falla a la mitad, que un rollback de
 * transaccion no borra).
 */
class IncomingEmailConvertAttachmentCapTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        Storage::fake('local');
    }

    private function makeIncomingEmailWithAttachments(int $count): IncomingEmail
    {
        $email = new IncomingEmail([
            'message_id' => 'msg-cap-'.$count,
            'from_email' => 'solicitante@example.com',
            'from_name' => 'Solicitante Externo',
            'subject' => 'Necesito ayuda con una falla',
            'body' => 'Descripción del problema reportado por correo.',
            'received_at' => now(),
        ]);
        $email->forceFill(['status' => IncomingEmailStatus::PendingReview])->save();

        for ($i = 1; $i <= $count; $i++) {
            $path = "incoming-emails/{$email->id}/original-{$i}.txt";
            Storage::disk('local')->put($path, "contenido {$i}");

            $email->attachments()->create([
                'original_name' => "evidencia-{$i}.txt",
                'path' => $path,
                'mime' => 'text/plain',
                'size' => 20,
            ]);
        }

        return $email;
    }

    public function test_convert_rejects_an_email_with_more_attachments_than_the_activity_can_hold_before_copying_any_file(): void
    {
        config(['tickets.attachments.max_per_ticket' => 1]);
        $email = $this->makeIncomingEmailWithAttachments(2);
        $sourcePaths = $email->attachments->pluck('path')->all();

        $activityData = [
            'title' => $email->subject,
            'description' => $email->body,
            'priority' => Priority::Medium->value,
            'responsible_id' => $this->empA1->id,
        ];

        try {
            app(IncomingEmailReviewService::class)->convert($this->coordA, $email, $activityData);
            $this->fail('Se esperaba BusinessRuleException por exceder el tope de adjuntos.');
        } catch (BusinessRuleException) {
            // esperado
        }

        // No se creo la actividad (todo dentro de la misma transaccion) ni ningun Attachment adoptado.
        $this->assertSame(0, Activity::query()->count());
        $this->assertSame(0, Attachment::query()->count());

        // El correo sigue pendiente de revision (no se marco como convertido).
        $email->refresh();
        $this->assertSame(IncomingEmailStatus::PendingReview, $email->status);

        // Los adjuntos ORIGINALES del correo siguen intactos y no se escribio ningun archivo nuevo en el
        // directorio de adjuntos de actividades (cero copias huerfanas).
        foreach ($sourcePaths as $path) {
            Storage::disk('local')->assertExists($path);
        }
        $this->assertSame([], Storage::disk('local')->allFiles('activities'));
    }

    public function test_convert_succeeds_when_attachments_fit_within_the_cap(): void
    {
        config(['tickets.attachments.max_per_ticket' => 2]);
        $email = $this->makeIncomingEmailWithAttachments(2);

        $activityData = [
            'title' => $email->subject,
            'description' => $email->body,
            'priority' => Priority::Medium->value,
            'responsible_id' => $this->empA1->id,
        ];

        $activity = app(IncomingEmailReviewService::class)->convert($this->coordA, $email, $activityData);

        $this->assertSame(2, $activity->attachments()->count());
    }
}
