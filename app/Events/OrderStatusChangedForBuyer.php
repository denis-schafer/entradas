<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Estado de una orden para el portal de compra.
 *
 * Canal privado con token opaco: 'tickets.order.{publicToken}'. El token es un
 * uuid de la orden, no el id autoincremental.routes/channels.php exige que la
 * orden pertenezca al usuario conectado, asi que un token filtrado no alcanza
 * para ver el estado de un pago ajeno.
 *
 * El payload no lleva datos del comprador: solo el estado.
 */
class OrderStatusChangedForBuyer implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $publicToken,
        public int $orderId,
        public string $status,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tickets.order.'.$this->publicToken)];
    }

    public function broadcastAs(): string
    {
        return 'order.status';
    }

    public function broadcastWith(): array
    {
        return [
            'orderId' => $this->orderId,
            'status' => $this->status,
        ];
    }
}