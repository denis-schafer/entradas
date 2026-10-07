<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambio de stock o de estado de venta de un tipo de entrada.
 *
 * Canal publico 'tickets.events': lo consumen la home, la ficha de compra y el
 * detalle del evento. Es publico a proposito, no lleva informacion sensible
 * (solo ids y numeros de stock) y asi evitamos un endpoint de autorizacion
 * extra para el portal.
 */
class TicketTypeStockChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $eventId,
        public int $ticketTypeId,
        public ?int $stock,
        public int $soldCount,
        public bool $enabled = true,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new Channel('tickets.events')];
    }

    public function broadcastAs(): string
    {
        return 'stock.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'eventId' => $this->eventId,
            'ticketTypeId' => $this->ticketTypeId,
            'stock' => $this->stock,
            'soldCount' => $this->soldCount,
            'enabled' => $this->enabled,
        ];
    }
}