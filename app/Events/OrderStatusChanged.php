<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Generic order/delivery status-change event.
 *
 * Fired by:
 *   - OrderController::store()                (NEW: 'created' — fires when ANY new order is placed,
 *                                               including delivery orders, so the admin dashboard
 *                                               finds out immediately instead of waiting for the
 *                                               next 15s poll)
 *   - OrderController::update()               (status: cooking/served/cancelled)
 *   - OrderController::assignRider()          (NEW: 'assigned' — was previously silent, meaning a
 *                                               rider assignment never pushed a live update to the
 *                                               dashboard or to any customer-facing tracker)
 *   - OrderController::updateDeliveryStatus() (delivery_status: assigned/picked_up/on_the_way/
 *                                               delivered/failed)
 *   - OrderController::cancel()               (status: cancelled)
 *   - PaymentController::reject()             (status: cancelled)
 *   - PaymentController::refund()             (status: refunded)
 *
 * CHANGES vs. the original version:
 *   1. loadMissing() now also loads 'table' and 'user', not just 'items'
 *      and 'rider'. Without these, the broadcast payload can be missing
 *      fields the frontend expects, which is a common cause of "works on
 *      manual refresh but not on the live push" bugs.
 *   2. total_amount is explicitly cast to float. Eloquent attribute
 *      casting can return a string-like decimal depending on the DB
 *      driver; casting here guarantees the frontend always receives a
 *      real number and Number()/toFixed() math never breaks.
 *   3. Added order_type, customer_name, customer_phone, delivery_address,
 *      and table to the payload. These are exactly the fields the
 *      Delivery Management page and the new Admin Dashboard delivery
 *      widgets need — without them those UIs would have to do a second
 *      API call just to fill in the blanks after a push event arrives.
 */
class OrderStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
        public string $newStatus
    ) {
        // Eager-load every relation any frontend listener might read from
        // this event's payload. Missing a relation here is the #1 cause of
        // "blank" or "$0.00" fields appearing only on the real-time push
        // but not on a manual refresh (which re-fetches from the full API
        // and therefore has everything).
        $this->order->loadMissing(['items.product', 'rider', 'table', 'user']);
    }

    /**
     * Public channel — acceptable for a single-tenant admin dashboard
     * where every admin is allowed to see every order. If this app ever
     * becomes multi-tenant, switch to:
     *   new PrivateChannel('orders.' . $this->order->id)
     * and add matching authorization in routes/channels.php.
     */
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