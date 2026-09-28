<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IncomingEmailStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\IncomingEmail;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revisión humana de un correo entrante (jefe de zona, administrador o coordinador): descartarlo con
 * motivo obligatorio, o convertirlo en una Actividad real (nunca un Ticket — instrucción explícita del
 * usuario). `lockForUpdate` en ambas operaciones evita que dos revisores actúen sobre el mismo correo a
 * la vez; una vez revisado (convertido o descartado), ninguna de las dos vuelve a estar disponible —ya
 * lo impone IncomingEmailPolicy, pero este servicio no confía en su llamador y vuelve a comprobarlo
 * contra el estado fresco bajo el lock (mismo patrón que StatusTransitioner/AssignmentService).
 */
final class IncomingEmailReviewService
{
    public function __construct(
        private readonly ActivityService $activities,
        private readonly AttachmentService $attachments,
        private readonly AuditLogger $audit,
    ) {}

    public function discard(User $actor, IncomingEmail $email, string $reason): IncomingEmail
    {
        return DB::transaction(function () use ($actor, $email, $reason): IncomingEmail {
            $locked = $this->lockPendingReview($email);

            $locked->forceFill([
                'status' => IncomingEmailStatus::Discarded,
                'discard_reason' => $reason,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ])->save();

            $this->audit->record('emails', 'discarded', $locked, $actor, [], [], ['reason' => $reason]);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $activityData  Ya validado por ConvertIncomingEmailRequest — misma
     *                                              forma que ActivityService::create() espera de
     *                                              StoreActivityRequest::validated(), sin `is_recurring`
     *                                              (una actividad convertida desde correo nunca es
     *                                              recurrente).
     */
    public function convert(User $actor, IncomingEmail $email, array $activityData): Activity
    {
        return DB::transaction(function () use ($actor, $email, $activityData): Activity {
            $locked = $this->lockPendingReview($email);

            $activity = $this->activities->create($actor, $activityData);

            // Se comprueba el tope ANTES de copiar ningun adjunto: adopt() ya se defiende sola en cada
            // llamada (borra el archivo que acaba de copiar si falla despues), pero un bucle que adopta
            // uno por uno puede fallar a la mitad (adjunto N supera el tope) dejando huerfanos en disco
            // los N-1 ya copiados, porque el rollback de la transaccion NO borra archivos. Se compara
            // dinamicamente contra los adjuntos que YA tiene la actividad destino (hoy siempre 0, recien
            // creada, pero sin asumirlo por si este metodo se reutiliza) mas los que se van a adoptar,
            // con el MISMO tope que usan AttachmentService::store()/adopt().
            $maxPerTicket = (int) config('tickets.attachments.max_per_ticket');

            if ($activity->attachments()->count() + count($locked->attachments) > $maxPerTicket) {
                throw BusinessRuleException::because('emails.errors.too_many_attachments_to_convert', ['max' => $maxPerTicket]);
            }

            foreach ($locked->attachments as $emailAttachment) {
                $this->attachments->adopt($actor, $activity, $emailAttachment);
            }

            $locked->forceFill([
                'status' => IncomingEmailStatus::Converted,
                'activity_id' => $activity->getKey(),
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ])->save();

            $this->audit->record('emails', 'converted', $locked, $actor, [], [], [
                'activity_id' => $activity->getKey(),
                'activity_folio' => $activity->folio,
            ]);

            return $activity;
        });
    }

    /**
     * Bloquea la fila y exige que siga pendiente de revisión; si ya se descartó o convirtió, se rechaza
     * con un mensaje de negocio (nunca un error del sistema).
     */
    private function lockPendingReview(IncomingEmail $email): IncomingEmail
    {
        /** @var IncomingEmail $locked */
        $locked = IncomingEmail::query()->lockForUpdate()->findOrFail($email->getKey());

        if ($locked->status !== IncomingEmailStatus::PendingReview) {
            throw BusinessRuleException::because('emails.errors.already_reviewed');
        }

        return $locked;
    }
}
