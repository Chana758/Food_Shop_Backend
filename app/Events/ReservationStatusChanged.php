<?php

namespace App\Events;

use App\Models\Reservation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Reservation status-change event — mirrors OrderStatusChanged exactly so
 * the admin dashboard can use the same useEcho broadcast-wide listening
 * pattern already wired up for orders/delivery.
 *
 * Fired by:
 *   - ReservationController::store()   (newStatus: 'created')
 *   - ReservationController::update()  (status: confirmed/rejected/completed/cancelled)
 *   - ReservationController::cancel()  (status: cancelled)
 */
class ReservationStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Reservation $reservation,
        public string $newStatus
    ) {
        $this->reservation->loadMissing(['user', 'table', 'confirmedBy']);
    }

    /**
     * Shared with the 'orders' broadcasting setup conceptually, but kept
     * on its own channel name so the AdminDashboard reservation widget
     * (if/when built) can subscribe independently of order traffic,
     * rather than filtering a mixed-purpose channel client-side.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('reservations'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'reservation.status.changed';
    }

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