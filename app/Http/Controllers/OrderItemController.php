<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderItemController extends Controller
{
    private function deliveryFee(): float
    {
        return (float) config('pricing.delivery_fee');
    }

    private function freeDeliveryThreshold(): float
    {
        return (float) config('pricing.free_delivery_threshold');
    }

    private const LOCKED_STATUSES = ['paid', 'refunded', 'cancelled'];

    private function effectiveUnitPrice(Product $product): float
    {
        $price    = (float) $product->price;
        $discount = $product->discount_price;

        $hasValidDiscount = $discount !== null
            && (float) $discount > 0
            && (float) $discount < $price
            && (! $product->discount_expires_at || \Carbon\Carbon::parse($product->discount_expires_at)->isFuture());

        return $hasValidDiscount ? (float) $discount : $price;
    }

    /**
     * Recompute order.total_amount from scratch based on its current
     * items — same pattern as OrderController::store(). Never
     * incrementally patch the old total; always derive it fresh so it
     * can never silently drift from what the items actually add up to.
     */
    private function recalculateOrderTotal(Order $order): void
    {
        $itemsTotal = (float) $order->items()->sum('subtotal');

        $deliveryFee = ($order->order_type === 'delivery'
            && $itemsTotal > 0
            && $itemsTotal < $this->freeDeliveryThreshold())
            ? $this->deliveryFee()
            : 0;

        $discount = min((float) $order->discount_amount, $itemsTotal);

        $order->update([
            'total_amount' => round($itemsTotal - $discount + $deliveryFee, 2),
        ]);
    }

    private function assertEditable(Order $order): void
    {
        if (in_array($order->status, self::LOCKED_STATUSES, true)) {
            abort(422, 'Cannot modify items on an order that is already ' . $order->status . '.');
        }
    }

    // ──────────────────────────────────────────────
    //  GET /api/admin/orders/{order}/items
    // ──────────────────────────────────────────────
    public function index(Order $order)
    {
        return response()->json([
            'status' => 'success',
            'data'   => $order->items()->with('product')->get(),
        ], 200);
    }

    // ──────────────────────────────────────────────
    //  POST /api/admin/orders/{order}/items
    //  Add one item to an EXISTING order.
    // ──────────────────────────────────────────────
    public function store(Request $request, Order $order)
    {
        $this->assertEditable($order);

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity'   => 'required|integer|min:1',
            'note'       => 'nullable|string',
        ]);

        try {
            $item = DB::transaction(function () use ($validated, $order) {
                $product = Product::lockForUpdate()->find($validated['product_id']);

                if (! $product) {
                    throw new \Exception("Product #{$validated['product_id']} not found.");
                }
                if ($product->stock_quantity < $validated['quantity']) {
                    throw new \Exception("Insufficient stock for: {$product->name}.");
                }

                $price    = $this->effectiveUnitPrice($product);
                $subtotal = round($price * $validated['quantity'], 2);

                $item = $order->items()->create([
                    'product_id' => $product->id,
                    'price'      => $price,
                    'quantity'   => $validated['quantity'],
                    'subtotal'   => $subtotal,
                    'note'       => $validated['note'] ?? null,
                ]);

                $product->decrement('stock_quantity', $validated['quantity']);

                $this->recalculateOrderTotal($order);

                return $item;
            });

            return response()->json([
                'status' => 'success',
                'data'   => $item->load('product'),
            ], 201);
        } catch (\Throwable $th) {
            Log::error('OrderItemController@store: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    // ──────────────────────────────────────────────
    //  PUT /api/order-items/{orderItem}
    // ──────────────────────────────────────────────
    public function update(Request $request, OrderItem $orderItem)
    {
        $order = $orderItem->order;
        $this->assertEditable($order);

        $validated = $request->validate([
            'quantity' => 'sometimes|required|integer|min:1',
            'note'     => 'nullable|string',
        ]);

        try {
            DB::transaction(function () use ($validated, $orderItem, $order) {
                if (isset($validated['quantity']) && $validated['quantity'] !== $orderItem->quantity) {
                    $product = Product::lockForUpdate()->find($orderItem->product_id);
                    $diff    = $validated['quantity'] - $orderItem->quantity; // +N needs more stock

                    if ($diff > 0 && $product->stock_quantity < $diff) {
                        throw new \Exception("Insufficient stock for: {$product->name}.");
                    }

                    // Positive diff decrements stock; negative diff
                    // (quantity reduced) correctly restores it.
                    $product->decrement('stock_quantity', $diff);

                    $orderItem->quantity = $validated['quantity'];
                    $orderItem->subtotal = round($orderItem->price * $validated['quantity'], 2);
                }

                if (array_key_exists('note', $validated)) {
                    $orderItem->note = $validated['note'];
                }

                $orderItem->save();

                $this->recalculateOrderTotal($order);
            });

            return response()->json([
                'status' => 'success',
                'data'   => $orderItem->fresh('product'),
            ], 200);
        } catch (\Throwable $th) {
            Log::error('OrderItemController@update: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    // ──────────────────────────────────────────────
    //  DELETE /api/order-items/{orderItem}
    // ──────────────────────────────────────────────
    public function destroy(OrderItem $orderItem)
    {
        $order = $orderItem->order;
        $this->assertEditable($order);

        try {
            DB::transaction(function () use ($orderItem, $order) {
                Product::where('id', $orderItem->product_id)
                    ->increment('stock_quantity', $orderItem->quantity);

                $orderItem->delete();

                $this->recalculateOrderTotal($order);
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Item removed from order.',
            ], 200);
        } catch (\Throwable $th) {
            Log::error('OrderItemController@destroy: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }
}