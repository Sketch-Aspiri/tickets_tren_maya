<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Coordination;
use App\Notifications\NewIncomingEmailToReview;
use App\Services\MailboxPoller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Recorre el buzon de cada coordinacion activa y deja los correos nuevos pendientes de revision. Cada buzon se
 * procesa aislado: si uno falla, los demas siguen y el comando termina con codigo 1. Los avisos de correo nuevo
 * van a los jefes de zona de ESA coordinacion (o a los administradores si no tiene jefe activo).
 */
class IngestIncomingEmailsCommand extends Command
{
    protected $signature = 'emails:ingest';

    protected $description = 'Descarga correos nuevos de cada buzon de coordinacion y los deja pendientes de revision (idempotente por Message-ID)';

    public function handle(MailboxPoller $poller): int
    {
        $failed = false;

        foreach ($poller->pollAll() as $outcome) {
            $coordination = Coordination::query()->find($outcome['coordination_id']);
            $label = $coordination?->name ?? '#'.$outcome['coordination_id'];

            if ($outcome['result'] === null) {
                $this->error(__('emails.command.mailbox_failed', ['coordination' => $label]));
                $failed = true;

                continue;
            }

            $this->info($label.': '.__('emails.command.done', $outcome['result']));
            $failed = $failed || ! $outcome['ok'];

            if ($outcome['result']['created'] > 0 && $coordination !== null) {
                Notification::send($coordination->approvers(), new NewIncomingEmailToReview($outcome['result']['created']));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
