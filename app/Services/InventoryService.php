<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;

/**
 * FIX: Centralized stock logic. Previously, "increment stock_quantity"
 * for restoring items was copy-pasted independently in THREE places
 * (OrderController::update() on cancel, OrderController::destroy(),
 * OrderController::cancel()). Any future change to restore logic (e.g.
 * adding a stock-movement audit log, handling variants, clamping to a
 * max stock level) would need to be remembered and applied in all three
 * spots — a classic source of drift bugs. Now there is exactly one
 * place that knows how to decrement and restore stock.
 */
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