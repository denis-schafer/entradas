<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Resultado de un escaneo en la puerta. Canal privado 'tickets.admin': lo
 * ven todos los operadores del panel, asi el supervisor sigue los escaneos
 * de cada lector en vivo.
 */
class TicketScanned implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $eventId,
        public ?int $ticketId,
        public string $result,
        public ?string $eventName = null,
        public ?string $ticketTypeName = null,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tickets.admin')];
    }

    public function broadcastAs(): string
    {
        return 'ticket.scanned';
    }

    public function broadcastWith(): array
    {
        return [
            'eventId' => $this->eventId,
            'ticketId' => $this->ticketId,
            'result' => $this->result,
            'eventName' => $this->eventName,
            'ticketTypeName' => $this->ticketTypeName,
        ];
    }
}