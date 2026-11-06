<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Concerns\BuildsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso al coordinador del equipo destino y a los jefes de su coordinación: llegó un ticket nuevo a la bolsa del
 * equipo (p. ej. alguien de otra coordinación escribió a Soporte del sistema). Solo escalares; tras el commit.
 */
final class TicketArrivedInPool extends Notification implements ShouldQueue
{
    use BuildsWebPush;
    use Queueable;

    public const TYPE = 'ticket_arrived_in_pool';

    public function __construct(
        public readonly int $ticketId,
        public readonly string $folio,
        public readonly string $title,
        public readonly string $teamName,
        public readonly string $creatorName,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'kind' => 'ticket',
            'id' => $this->ticketId,
            'folio' => $this->folio,
            'title' => $this->title,
            'team' => $this->teamName,
            'by' => $this->creatorName,
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->webPush(
            __('push.arrived.title'),
            __('push.arrived.body', ['folio' => $this->folio, 'team' => $this->teamName, 'by' => $this->creatorName]),
            $this->itemUrl('ticket', $this->ticketId),
            'arrived-ticket-'.$this->ticketId,
        );
    }
}
