<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\NewIncomingEmailToReview;
use App\Services\EmailIngestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Descarga correos nuevos de la bandeja IMAP configurada y los deja pendientes de revisión humana
 * (jefe de zona, administrador o coordinador), idempotente por Message-ID. Se programa en
 * routes/console.php cada `mail_ingestion.interval_minutes` minutos.
 */
class IngestIncomingEmailsCommand extends Command
{
    protected $signature = 'emails:ingest';

    protected $description = 'Descarga correos nuevos y los deja pendientes de revision (idempotente por Message-ID)';

    public function handle(EmailIngestionService $ingestion): int
    {
        $result = $ingestion->ingest();

        $this->info(__('emails.command.done', $result));

        if ($result['created'] > 0) {
            $recipients = User::query()
                ->role([UserRole::Administrador->value, UserRole::JefeZona->value])
                ->where('status', UserStatus::Active->value)
                ->get();

            Notification::send($recipients, new NewIncomingEmailToReview($result['created']));
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
