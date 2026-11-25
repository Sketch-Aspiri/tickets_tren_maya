<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Jobs\SendPortalNotice;
use App\Models\User;
use App\Notifications\Channels\PortalChannel;
use App\Notifications\NewIncomingEmailToReview;
use App\Notifications\NewUserPendingApproval;
use Illuminate\Support\Facades\Bus;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\DatabaseTestCase;

/** Correos por revisar y cuentas por aprobar también avisan al celular (y al portal cuando es el destino). */
class AdminNoticesPushTest extends DatabaseTestCase
{
    public function test_both_notices_use_the_configured_push_channel(): void
    {
        $jefe = User::factory()->jefe()->create();
        $avisos = [new NewIncomingEmailToReview(3), new NewUserPendingApproval(7, 'Persona Nueva', 'nueva@example.com')];

        foreach ($avisos as $aviso) {
            config(['ecosistema.push.destino' => 'local']);
            $this->assertSame(['database', 'mail', WebPushChannel::class], $aviso->via($jefe));

            config(['ecosistema.push.destino' => 'portal']);
            $this->assertSame(['database', 'mail', PortalChannel::class], $aviso->via($jefe));
        }
    }

    public function test_the_push_text_never_carries_the_applicant_name_or_email(): void
    {
        $jefe = User::factory()->jefe()->create();
        $aviso = new NewUserPendingApproval(7, 'Persona Nueva', 'nueva@example.com');

        $mensaje = $aviso->toWebPush($jefe, $aviso)->toArray();

        $this->assertSame('/users/7', $mensaje['data']['url']);
        $this->assertStringNotContainsString('Persona', json_encode($mensaje));
        $this->assertStringNotContainsString('nueva@example.com', json_encode($mensaje));
    }

    public function test_both_notices_are_sent_to_the_portal_with_absolute_urls(): void
    {
        Bus::fake([SendPortalNotice::class]);
        config(['ecosistema.push.destino' => 'portal', 'ecosistema.urls.tickets' => 'https://tickets.example.test']);
        $jefe = User::factory()->jefe()->create(['email' => 'jefa@example.com']);

        foreach ([new NewIncomingEmailToReview(2), new NewUserPendingApproval(7, 'Persona Nueva', 'nueva@example.com')] as $aviso) {
            (new PortalChannel)->send($jefe, $aviso);
        }

        foreach (['/incoming-emails', '/users/7'] as $ruta) {
            Bus::assertDispatched(SendPortalNotice::class, fn (SendPortalNotice $job): bool => $job->url === 'https://tickets.example.test'.$ruta
                && $job->emails === ['jefa@example.com'] && mb_strlen($job->title) <= 60 && mb_strlen($job->body) <= 120);
        }
    }
}
