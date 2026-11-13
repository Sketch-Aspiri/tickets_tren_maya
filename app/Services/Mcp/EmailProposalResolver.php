<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Enums\AssignmentRole;
use App\Enums\EmailProposalStatus;
use App\Models\Activity;
use App\Models\EmailActivityProposal;
use App\Models\IncomingEmail;
use App\Models\User;

/**
 * Cierra la propuesta de la IA cuando una persona decide sobre el correo (decision 94). Lo llama
 * `IncomingEmailReviewService` dentro de su transaccion: convertir confirma la propuesta (y registra si se acepto
 * SIN cambios, que es la metrica para pasar al modo automatico); descartar la rechaza.
 */
final class EmailProposalResolver
{
    public function onConverted(IncomingEmail $email, Activity $activity, User $actor): void
    {
        $proposal = $this->pending($email);

        if ($proposal === null) {
            return;
        }

        $responsibleId = (int) $activity->assignments()->where('role', AssignmentRole::Responsable->value)->value('user_id');

        $unchanged = (int) $proposal->team_id === (int) $activity->team_id
            && (int) $proposal->responsible_id === $responsibleId
            && $proposal->due_date->toDateString() === $activity->due_date?->toDateString();

        $proposal->forceFill([
            'status' => EmailProposalStatus::Accepted,
            'unchanged' => $unchanged,
            'decided_by' => $actor->getKey(),
            'decided_at' => now(),
        ])->save();
    }

    public function onDiscarded(IncomingEmail $email, User $actor): void
    {
        $this->pending($email)?->forceFill([
            'status' => EmailProposalStatus::Rejected,
            'decided_by' => $actor->getKey(),
            'decided_at' => now(),
        ])->save();
    }

    private function pending(IncomingEmail $email): ?EmailActivityProposal
    {
        return EmailActivityProposal::query()
            ->where('incoming_email_id', $email->getKey())
            ->where('status', EmailProposalStatus::Pending->value)
            ->latest('id')
            ->first();
    }
}
