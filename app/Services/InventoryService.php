<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;

class InventoryService
{
    public function decrementStock(Order $order): void
    {
        $order->loadMissing('items'); //  FIX: relation ឈ្មោះ 'items' មិនមែន 'orderItems'

        foreach ($order->items as $item) {
            $product = Product::find($item->product_id);

            if ($product) {
                $product->decrement('stock_quantity', $item->quantity);
            }
        }
    }
}