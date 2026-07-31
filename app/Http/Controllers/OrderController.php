<?php

namespace App\Http\Controllers;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    private const VALID_STATUSES = [
        'pending', 'cooking', 'served', 'paid', 'cancelled',
    ];

    private const VALID_ORDER_TYPES = [
        'dine-in', 'takeaway', 'delivery',
    ];

    private const VALID_DELIVERY_STATUSES = [
        'unassigned', 'assigned', 'picked_up', 'on_the_way', 'delivered', 'failed',
    ];

    /**
     * ✅ NEW — fire an OrderStatusChanged broadcast without ever letting a
     * failure escape.
     *
     * ROOT CAUSE OF THE BUG: every DB::afterCommit(fn () => event(...))
     * callback in this controller ran INSIDE the same try/catch block as
     * the database work above it. By the time afterCommit fires, the
     * transaction has already committed successfully — stock is
     * decremented, the order row exists. But if broadcasting throws
     * (e.g. Reverb isn't running: "cURL error 7: Failed to connect to
     * 127.0.0.1 port 8080"), that exception was caught by the SAME
     * catch (\Throwable $th) block and turned into a 500 response with
     * the raw connection error leaked straight to the customer's browser
     * — even though their order had already gone through. The customer
     * saw "Order Failed" for an order that actually succeeded, with no
     * way to know it, risking a duplicate order and a double stock
     * deduction if they retried.
     *
     * Broadcasting a "hey, something changed" notification is a
     * nice-to-have for the admin dashboard's real-time updates. It must
     * never be allowed to make an already-successful business operation
     * look like it failed. So every call site below is now wrapped here:
     * failures are logged for ops visibility, then swallowed.
     */
    private function safeBroadcast(Order $order, string $newStatus): void
    {
        try {
            event(new OrderStatusChanged($order, $newStatus));
        } catch (\Throwable $th) {
            Log::warning("OrderController: broadcast failed for order #{$order->id} (status: {$newStatus}) — {$th->getMessage()}");
        }
    }

    // ──────────────────────────────────────────────
    //  ADMIN ENDPOINTS
    // ──────────────────────────────────────────────

    /**
     * GET /api/admin/orders
     * List all orders with optional filters (admin only).
     */
    public function index(Request $request)
    {
        try {
            $orders = Order::with('items.product', 'table', 'user', 'rider')
                ->when($request->status,          fn($q) => $q->where('status',          $request->status))
                ->when($request->order_type,      fn($q) => $q->where('order_type',      $request->order_type))
                ->when($request->delivery_status, fn($q) => $q->where('delivery_status', $request->delivery_status))
                ->when($request->rider_id,        fn($q) => $q->where('rider_id',        $request->rider_id))
                ->when($request->user_id,         fn($q) => $q->where('user_id',         $request->user_id))
                ->when($request->date_from, fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
                ->when($request->date_to,   fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
                ->latest()
                ->paginate($request->per_page ?? 15);

            return response()->json([
                'status' => 'success',
                'data'   => $orders,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('OrderController@index: ' . $th->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/orders/:id
     * Show single order — admin sees all, customer sees own only.
     */
    public function show(Order $order)
    {
        $user = auth()->user();

        if ($user->role !== 'admin' && $order->user_id !== $user->id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized',
            ], 403);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $order->load('items.product', 'table', 'user', 'rider'),
        ], 200);
    }

    /**
     * PUT /api/admin/orders/:id
     * Admin updates order status, table, or notes.
     */
    public function update(Request $request, Order $order)
    {
        try {
            $validated = $request->validate([
                'status'   => 'sometimes|in:' . implode(',', self::VALID_STATUSES),
                'table_id' => 'nullable|exists:tables,id',
                'notes'    => 'nullable|string',
            ]);

            DB::transaction(function () use ($order, $validated) {
                if (
                    ($validated['status'] ?? null) === 'cancelled' &&
                    $order->status !== 'cancelled'
                ) {
                    foreach ($order->items as $item) {
                        $item->product()->increment('stock_quantity', $item->quantity);
                    }
                }
                $order->update($validated);
            });

            if (isset($validated['status'])) {
                // ✅ FIX — was event(new OrderStatusChanged(...)) directly;
                // now routed through safeBroadcast() so a Reverb outage
                // can't turn this already-successful update into a 500.
                DB::afterCommit(function () use ($order, $validated) {
                    $this->safeBroadcast($order->fresh(), $validated['status']);
                });
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Order updated successfully.',
                'data'    => $order->fresh('items.product', 'table', 'user', 'rider'),
            ], 200);

        } catch (\Throwable $th) {
            Log::error('OrderController@update: ' . $th->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/admin/orders/:id
     * Hard-delete; restores stock unless already paid/cancelled.
     */
    public function destroy(Order $order)
    {
        try {
            DB::transaction(function () use ($order) {
                if (! in_array($order->status, ['paid', 'cancelled'])) {
                    foreach ($order->items as $item) {
                        $item->product()->increment('stock_quantity', $item->quantity);
                    }
                }
                $order->delete();
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Order deleted successfully.',
            ], 200);

        } catch (\Throwable $th) {
            Log::error('OrderController@destroy: ' . $th->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/admin/orders/{order}/assign-rider
     * Assign a rider to a delivery order.
     */
    public function assignRider(Request $request, Order $order)
    {
        $validated = $request->validate([
            'rider_id' => 'required|exists:riders,id',
        ]);

        if ($order->order_type !== 'delivery') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Only delivery orders can be assigned a rider.',
            ], 422);
        }

        if (in_array($order->delivery_status, ['delivered', 'failed'])) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cannot assign rider — delivery already ' . $order->delivery_status . '.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($order, $validated) {
                // If reassigning to a different rider, free up the
                // previous rider first so they go back into the
                // available pool.
                if ($order->rider_id && $order->rider_id !== $validated['rider_id']) {
                    Rider::where('id', $order->rider_id)->update(['status' => 'available']);
                }

                $order->update([
                    'rider_id'        => $validated['rider_id'],
                    'delivery_status' => 'assigned',
                ]);

                Rider::where('id', $validated['rider_id'])->update(['status' => 'busy']);
            });

            // ✅ FIX — routed through safeBroadcast()
            DB::afterCommit(function () use ($order) {
                $this->safeBroadcast($order->fresh('rider'), 'assigned');
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Rider assigned successfully.',
                'data'    => $order->fresh('rider'),
            ], 200);

        } catch (\Throwable $th) {
            Log::error('OrderController@assignRider: ' . $th->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/admin/orders/{order}/delivery-status
     * Update delivery progress; releases rider when done.
     */
    public function updateDeliveryStatus(Request $request, Order $order)
    {
        $validated = $request->validate([
            'delivery_status' => 'required|in:' . implode(',', self::VALID_DELIVERY_STATUSES),
        ]);

        if ($order->order_type !== 'delivery') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Only delivery orders have a delivery status.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($order, $validated) {
                $order->update(['delivery_status' => $validated['delivery_status']]);

                if (
                    in_array($validated['delivery_status'], ['delivered', 'failed']) &&
                    $order->rider_id
                ) {
                    Rider::where('id', $order->rider_id)->update(['status' => 'available']);
                }
            });

            // FIX — routed through safeBroadcast()
            DB::afterCommit(function () use ($order, $validated) {
                $this->safeBroadcast($order->fresh('rider'), $validated['delivery_status']);
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Delivery status updated.',
                'data'    => $order->fresh('rider'),
            ], 200);

        } catch (\Throwable $th) {
            Log::error('OrderController@updateDeliveryStatus: ' . $th->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    // ──────────────────────────────────────────────
    //  CUSTOMER ENDPOINTS
    // ──────────────────────────────────────────────

    /**
     * POST /api/orders
     * Customer places a dine-in / takeaway / delivery order.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'table_id'           => 'nullable|exists:tables,id',
            'order_type'         => 'required|in:' . implode(',', self::VALID_ORDER_TYPES),
            'notes'              => 'nullable|string',
            'customer_name'      => 'required_if:order_type,delivery|nullable|string|max:255',
            'customer_phone'     => 'required_if:order_type,delivery|nullable|string|max:20',
            'delivery_address'   => 'required_if:order_type,delivery|nullable|string',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.note'       => 'nullable|string',
        ]);

        if ($validated['order_type'] === 'dine-in' && empty($validated['table_id'])) {
            return response()->json([
                'status'  => 'error',
                'message' => 'table_id is required for dine-in orders.',
            ], 422);
        }

        try {
            $order = DB::transaction(function () use ($validated) {
                $totalAmount = 0;
                $itemsData   = [];

                foreach ($validated['items'] as $item) {
                    $product = Product::lockForUpdate()->find($item['product_id']);

                    if (! $product) {
                        throw new \Exception("Product #{$item['product_id']} not found.");
                    }

                    if ($product->stock_quantity < $item['quantity']) {
                        throw new \Exception("Insufficient stock for: {$product->name}.");
                    }

                    $price        = $product->discount_price ?? $product->price;
                    $lineSubtotal = $price * $item['quantity'];
                    $totalAmount += $lineSubtotal;

                    $itemsData[] = [
                        'product_id' => $product->id,
                        'price'      => $price,
                        'quantity'   => $item['quantity'],
                        'subtotal'   => $lineSubtotal,
                        'note'       => $item['note'] ?? null,
                    ];

                    $product->decrement('stock_quantity', $item['quantity']);
                }

                $order = Order::create([
                    'user_id'          => auth()->id(),
                    'table_id'         => $validated['table_id'] ?? null,
                    'order_type'       => $validated['order_type'],
                    'status'           => 'pending',
                    'total_amount'     => $totalAmount,
                    'notes'            => $validated['notes'] ?? null,
                    'customer_name'    => $validated['customer_name']    ?? null,
                    'customer_phone'   => $validated['customer_phone']   ?? null,
                    'delivery_address' => $validated['delivery_address'] ?? null,
                    'delivery_status'  => $validated['order_type'] === 'delivery' ? 'unassigned' : null,
                ]);

                foreach ($itemsData as $data) {
                    $order->items()->create($data);
                }

                return $order;
            });

            //  FIX — THIS is the exact line that caused the bug. It used
            // to be `event(new OrderStatusChanged(...))` directly, so a
            // Reverb connection failure here threw an exception that got
            // caught below and returned as a 500 to the customer — AFTER
            // their order had already been created and stock already
            // decremented. Now routed through safeBroadcast(), which logs
            // the failure but never lets it affect the HTTP response.
            DB::afterCommit(function () use ($order) {
                $this->safeBroadcast(
                    $order->fresh('items.product', 'table', 'user', 'rider'),
                    'created'
                );
            });

            return response()->json([
                'status' => 'success',
                'data'   => $order->load('items.product', 'table', 'user', 'rider'),
            ], 201);

        } catch (\Throwable $th) {
            Log::error('OrderController@store: ' . $th->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/orders
     * Customer views their own order history (ownership-filtered).
     */
    public function myOrders(Request $request)
    {
        try {
            $orders = Order::with('items.product', 'table', 'rider')
                ->where('user_id', auth()->id())
                ->when($request->status,     fn($q) => $q->where('status',     $request->status))
                ->when($request->order_type, fn($q) => $q->where('order_type', $request->order_type))
                ->latest()
                ->paginate($request->per_page ?? 10);

            return response()->json([
                'status' => 'success',
                'data'   => $orders,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('OrderController@myOrders: ' . $th->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/orders/{order}/cancel
     * Customer cancels their own pending order; restores stock.
     */
    public function cancel(Order $order)
    {
        if ($order->user_id !== auth()->id()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized',
            ], 403);
        }

        if ($order->status !== 'pending') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Only pending orders can be cancelled. Current status: ' . $order->status,
            ], 422);
        }

        try {
            DB::transaction(function () use ($order) {
                foreach ($order->items as $item) {
                    $item->product()->increment('stock_quantity', $item->quantity);
                }
                $order->update(['status' => 'cancelled']);
            });

            //  FIX — routed through safeBroadcast()
            DB::afterCommit(function () use ($order) {
                $this->safeBroadcast($order->fresh(), 'cancelled');
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Order cancelled.',
                'data'    => $order->fresh('items.product'),
            ], 200);

        } catch (\Throwable $th) {
            Log::error('OrderController@cancel: ' . $th->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/admin/orders/stats
     * Aggregate stats for admin dashboard.
     */
    public function stats()
    {
        try {
            $stats = [
                'by_status' => Order::selectRaw('status, COUNT(*) as count')
                    ->groupBy('status')->get(),

                'by_type' => Order::selectRaw('order_type, COUNT(*) as count, SUM(total_amount) as revenue')
                    ->groupBy('order_type')->get(),

                'by_delivery_status' => Order::where('order_type', 'delivery')
                    ->selectRaw('delivery_status, COUNT(*) as count')
                    ->groupBy('delivery_status')->get(),

                'today' => [
                    'orders'  => Order::whereDate('created_at', today())->count(),
                    'revenue' => Order::whereDate('created_at', today())
                        ->where('status', 'paid')->sum('total_amount'),
                ],

                'this_month' => [
                    'orders'  => Order::whereMonth('created_at', now()->month)
                        ->whereYear('created_at', now()->year)->count(),
                    'revenue' => Order::whereMonth('created_at', now()->month)
                        ->whereYear('created_at', now()->year)
                        ->where('status', 'paid')->sum('total_amount'),
                ],

                'top_products' => DB::table('order_items')
                    ->join('products', 'order_items.product_id', '=', 'products.id')
                    ->join('orders',   'order_items.order_id',   '=', 'orders.id')
                    ->where('orders.status', 'paid')
                    ->selectRaw('products.id, products.name, SUM(order_items.quantity) as total_sold, SUM(order_items.subtotal) as revenue')
                    ->groupBy('products.id', 'products.name')
                    ->orderByDesc('total_sold')
                    ->limit(10)
                    ->get(),
            ];

            return response()->json([
                'status' => 'success',
                'data'   => $stats,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('OrderController@stats: ' . $th->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage(),
            ], 500);
        }
    }
}