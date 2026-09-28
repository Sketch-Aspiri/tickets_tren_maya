<?php

declare(strict_types=1);

namespace Tests\Feature\Emails;

use App\Enums\IncomingEmailStatus;
use App\Models\IncomingEmail;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * Formulario de conversion de un correo entrante en Actividad: reutiliza `activities._form` (decision 5
 * de la revision de codigo) con `showRecurrence: false`. Comprueba que el refactor no rompe el render ni
 * el prellenado de titulo/descripcion desde el correo de origen.
 */
class IncomingEmailConversionFormTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    private function makeIncomingEmail(): IncomingEmail
    {
        $email = new IncomingEmail([
            'message_id' => 'msg-convert-form-1',
            'from_email' => 'solicitante@example.com',
            'from_name' => 'Solicitante Externo',
            'subject' => 'Necesito ayuda con una falla',
            'body' => 'Descripción del problema reportado por correo.',
            'received_at' => now(),
        ]);
        $email->forceFill(['status' => IncomingEmailStatus::PendingReview])->save();

        return $email;
    }

    public function test_the_conversion_form_prefills_title_and_description_from_the_email_and_hides_recurrence(): void
    {
        $email = $this->makeIncomingEmail();

        $html = $this->signIn($this->coordA)->get("/incoming-emails/{$email->id}/convert")->assertOk()->getContent();

        $this->assertStringContainsString('value="Necesito ayuda con una falla"', $html);
        $this->assertStringContainsString('Descripción del problema reportado por correo.', $html);
        $this->assertStringContainsString('name="responsible_id"', $html);
        $this->assertStringContainsString('name="title"', $html);
        $this->assertStringContainsString('name="description"', $html);

        // Sin editor de recurrencia: una actividad convertida desde correo nunca es recurrente.
        $this->assertStringNotContainsString('name="is_recurring"', $html);
        $this->assertStringNotContainsString('recurrenceEditor', $html);
        $this->assertStringNotContainsString('name="recurrence[frequency]"', $html);

        $this->assertStringContainsString(__('emails.convert.submit'), $html);
        $this->assertStringContainsString(route('incoming-emails.show', $email), $html);
    }

    public function test_an_employee_cannot_reach_the_conversion_form(): void
    {
        $email = $this->makeIncomingEmail();

        $this->signIn($this->empA1)->get("/incoming-emails/{$email->id}/convert")->assertForbidden();
    }
}
