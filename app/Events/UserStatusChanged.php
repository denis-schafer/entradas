<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambio de estado (habilitado/deshabilitado) de un usuario del panel.
 *
 * Canal privado por usuario: 'tickets.user.{id}'. Solo el propio usuario lo
 * escucha (ver routes/channels.php). Se usa para desloguearlo al instante
 * cuando un administrador lo desactiva, sin esperar a que haga un request.
 */
class UserStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public bool $enable,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tickets.user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'user.status';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->userId,
            'enable' => $this->enable,
        ];
    }
}
