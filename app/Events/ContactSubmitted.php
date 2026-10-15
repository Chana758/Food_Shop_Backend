<?php

namespace App\Events;

use App\Models\Contact;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContactSubmitted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Contact $contact)
    {
    }

    /**
     * Broadcast on the existing orders channel.
     */
    public function broadcastOn(): Channel
    {
        return new Channel('orders');
    }

    /**
     * Reuse the existing frontend event listener.
     */
    public function broadcastAs(): string
    {
        return 'order.status.changed';
    }

    // Send the minimal payload required by the dashboard.
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