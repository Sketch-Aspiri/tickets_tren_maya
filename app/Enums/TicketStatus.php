<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estados de un ticket (y, en el Sprint 3, de una actividad). El grafo de transiciones vive aqui,
 * en un solo lugar; TicketService lo aplica y TicketPolicy decide QUIEN puede recorrer cada arista.
 * "Vencido" NO es un estado: es un calculo (Ticket::scopeOverdue).
 */
enum TicketStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('enums.ticket_status.'.$this->value);
    }

    /**
     * Estados finales: no admiten edicion ni asignacion; solo reabrir.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Estados a los que se puede pasar desde este.
     *
     * @return list<self>
     */
    public function allowedTargets(): array
    {
        return match ($this) {
            self::Pending => [self::InProgress, self::Cancelled],
            self::InProgress => [self::InReview, self::Cancelled],
            self::InReview => [self::Completed, self::InProgress, self::Cancelled],
            self::Completed, self::Cancelled => [self::Pending],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTargets(), true);
    }

    /**
     * Rechazar (En revisión -> En proceso) y reabrir (final -> Pendiente) exigen comentario.
     */
    public function requiresCommentWhenMovingTo(self $target): bool
    {
        return ($this === self::InReview && $target === self::InProgress)
            || ($this->isFinal() && $target === self::Pending);
    }

    /**
     * Clave de traduccion (`tickets.actions.transition.*`) del boton que lleva de `$from` a este estado.
     * Rechazar (En revision -> En proceso) se distingue de "iniciar trabajo".
     */
    public function actionLabelKeyFrom(self $from): string
    {
        return $from === self::InReview && $this === self::InProgress ? 'reject' : $this->value;
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => ! $status->isFinal()));
    }

    /**
     * @return list<string>
     */
    public static function finalValues(): array
    {
        return array_map(
            fn (self $status): string => $status->value,
            array_values(array_filter(self::cases(), fn (self $status): bool => $status->isFinal())),
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
