<?php

declare(strict_types=1);

namespace Tests\Feature\Emails;

use App\Enums\IncomingEmailStatus;
use App\Models\IncomingEmail;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

class IncomingEmailConvertRedirectTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    public function test_converting_an_email_redirects_to_a_page_that_renders(): void
    {
        $email = new IncomingEmail([
            'message_id' => 'msg-redirect-1',
            'from_email' => 'externo@example.com',
            'from_name' => 'Externo',
            'subject' => 'Promoción: próxima estancia ñandú',
            'body' => "Línea uno\n\nLínea dos con acentos áéíóú",
            'received_at' => now(),
        ]);
        $email->forceFill(['status' => IncomingEmailStatus::PendingReview])->save();

        $response = $this->signIn($this->coordA)->post("/incoming-emails/{$email->id}/convert", [
            'title' => $email->subject,
            'description' => $email->body,
            'priority' => 'medium',
            'team_id' => $this->coordA->team_id,
            'responsible_id' => $this->empA1->id,
        ]);

        $response->assertSessionHasNoErrors();
        $location = $response->assertRedirect()->headers->get('Location');

        $this->get($location)->assertOk();
    }
}
