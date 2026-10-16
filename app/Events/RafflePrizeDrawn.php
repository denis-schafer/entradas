<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se sorteo un premio.
 *
 * Va por un canal PUBLICO 'raffle.{public_token}' porque la ventana de
 * proyeccion no tiene sesion: el token del sorteo es la unica credencial y es
 * aleatorio. El payload es minimo (el proyector igual pide el estado completo
 * al endpoint publico), pero alcanza para animar la revelacion sin esperar.
 */
class RafflePrizeDrawn implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $publicToken,
        public int $raffleId,
        public int $prizeId,
        public int $position,
        public ?string $prizeName,
        public ?int $ticketNumber,
        public ?string $winnerName,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new Channel('raffle.'.$this->publicToken)];
    }

    public function broadcastAs(): string
    {
        return 'raffle.prize.drawn';
    }

    public function broadcastWith(): array
    {
        return [
            'raffleId' => $this->raffleId,
            'prizeId' => $this->prizeId,
            'position' => $this->position,
            'prizeName' => $this->prizeName,
            'ticketNumber' => $this->ticketNumber,
            'winnerName' => $this->winnerName,
        ];
    }
}
