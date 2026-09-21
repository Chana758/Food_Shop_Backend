<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;

class InventoryService
{
    /**
     * Decrement stock for every item on the order (called when an order
     * is placed / paid, i.e. items are "going out the door").
     */
    public function decrementStock(Order $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            $product = Product::find($item->product_id);

            if ($product) {
                $product->decrement('stock_quantity', $item->quantity);
            }
        }
    }

    /**
     * Restore stock for every item on the order (called when an order is
     * cancelled or deleted before it was ever paid/fulfilled).
     */
    public function restoreStock(Order $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            $product = Product::find($item->product_id);

            if ($product) {
                $product->increment('stock_quantity', $item->quantity);
            }
        }
    }
}