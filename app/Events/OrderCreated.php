<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Orden creada desde el portal. Canal privado 'tickets.admin', para que el
 * panel la vea aparecer sin recargar.
 */
class OrderCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $orderId,
        public int $eventId,
        public string $buyerName,
        public string $total,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tickets.admin')];
    }

    public function broadcastAs(): string
    {
        return 'order.created';
    }

    public function broadcastWith(): array
    {
        return [
            'orderId' => $this->orderId,
            'eventId' => $this->eventId,
            'buyerName' => $this->buyerName,
            'total' => $this->total,
        ];
    }
}