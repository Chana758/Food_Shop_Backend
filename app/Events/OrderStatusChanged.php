<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;


class OrderStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
        public string $newStatus
    ) {
       
        $this->order->loadMissing(['items.product', 'rider', 'table', 'user']);
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('orders'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'order' => [
                'id'               => $this->order->id,
                'status'           => $this->order->status,
                'delivery_status'  => $this->order->delivery_status,
                'order_type'       => $this->order->order_type,
                'new_status'       => $this->newStatus,
                'total_amount'     => (float) $this->order->total_amount,
                'customer_name'    => $this->order->customer_name ?? $this->order->user?->name,
                'customer_phone'   => $this->order->customer_phone,
                'delivery_address' => $this->order->delivery_address,
                'table'            => $this->order->table ? [
                    'id'   => $this->order->table->id,
                    'name' => $this->order->table->name ?? null,
                ] : null,
                'rider' => $this->order->rider ? [
                    'id'    => $this->order->rider->id,
                    'name'  => $this->order->rider->name,
                    'phone' => $this->order->rider->phone,
                ] : null,
                'updated_at' => $this->order->updated_at?->toISOString(),
            ], 
        ];
    }
}