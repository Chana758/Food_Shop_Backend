<?php

namespace App\Events;

use App\Models\Reservation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReservationStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Reservation $reservation,
        public string $newStatus
    ) {
        $this->reservation->loadMissing(['user', 'table', 'confirmedBy']);
    }

    // Broadcast on the reservations channel.ther than filtering a mixed-purpose channel client-side.
    public function broadcastOn(): array
    {
        return [
            new Channel('reservations'),
        ];
    }

    // Match the frontend event listener.
    public function broadcastAs(): string
    {
        return 'reservation.status.changed';
    }
    
    // Send reservation details to subscribers.
    public function broadcastWith(): array
    {
        return [
            'reservation' => [
                'id'           => $this->reservation->id,
                'status'       => $this->reservation->status,
                'new_status'   => $this->newStatus,
                'guest_count'  => $this->reservation->guest_count,
                'reserved_at'  => $this->reservation->reserved_at?->toISOString(),
                'customer_name'=> $this->reservation->user?->name,
                'table'        => $this->reservation->table ? [
                    'id'   => $this->reservation->table->id,
                    'name' => $this->reservation->table->name ?? null,
                ] : null,
                'confirmed_by' => $this->reservation->confirmedBy?->name,
                'updated_at'   => $this->reservation->updated_at?->toISOString(),
            ],
        ];
    }
}