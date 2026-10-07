<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Estado de una orden para el panel de administracion.
 *
 * Canal privado 'tickets.admin'. Se dispara despues del commit, asi que el
 * cliente puede refetchear sin riesgo de leer datos viejos.
 */
class OrderStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $orderId,
        public string $status,
        public string $total,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tickets.admin')];
    }

    public function broadcastAs(): string
    {
        return 'order.status';
    }

    /**
     * Payload minimo: identificadores y el valor nuevo. Nunca montos que el
     * cliente pueda confiar sin volver a consultar.
     */
    public function broadcastWith(): array
    {
        return [
            'orderId' => $this->orderId,
            'status' => $this->status,
            'total' => $this->total,
        ];
    }
}