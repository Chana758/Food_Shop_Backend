<?php

namespace App\Events;

use App\Models\Contact;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast a new contact message to the admin dashboard in real time.
 *
 * Reuses the existing 'orders' channel and 'order.status.changed' event
 * name so that AdminDashboard.jsx's useEcho(null, { onAnyChange }) picks
 * it up without any frontend changes — it already refetches the whole
 * dashboard (including contact stats) on any event on that channel.
 */
class ContactSubmitted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Contact $contact)
    {
    }

    /**
     * The channel this event broadcasts on.
     */
    public function broadcastOn(): Channel
    {
        return new Channel('orders');
    }

    /**
     * The event name the frontend listens for.
     * Kept identical to the order status event so no new Echo listener
     * is needed — AdminDashboard's onAnyChange already fires on this.
     */
    public function broadcastAs(): string
    {
        return 'order.status.changed';
    }

    /**
     * Data sent to the frontend.
     * `type: 'contact'` lets any listener distinguish this from a real
     * order event if it ever needs to; useEcho's dashboard-wide mode
     * ignores it and just refetches, which is all we need here.
     */
    public function broadcastWith(): array
    {
        return [
            'order' => [
                'id'         => $this->contact->id,
                'new_status' => 'contact_submitted',
            ],
            'type' => 'contact',
        ];
    }
}