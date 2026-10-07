<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambio de estado de un evento (draft -> published -> closed/cancelled).
 * Canal publico 'tickets.events'.
 */
class EventStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $eventId,
        public string $status,
        public ?string $coverImage = null,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new Channel('tickets.events')];
    }

    public function broadcastAs(): string
    {
        return 'event.status';
    }

    public function broadcastWith(): array
    {
        return [
            'eventId' => $this->eventId,
            'status' => $this->status,
            'coverImage' => $this->coverImage,
        ];
    }
}