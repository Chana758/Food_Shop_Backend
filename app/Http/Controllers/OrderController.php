<?php

namespace App\Http\Controllers;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    /**
     * All statuses that are considered valid on the `orders` table.
     * Used for general validation (e.g. filtering in index()).
     */
    private const VALID_STATUSES = [
        'pending', 'cooking', 'served', 'paid', 'cancelled',
    ];

    /**
     * FIX: Statuses an admin is allowed to set directly through
     * PUT /admin/orders/:id.
     *
     * ROOT CAUSE OF THE BUG: 'paid' used to be included in the statuses
     * accepted by update(). That let an admin mark an order 'paid'
     * directly from the Order Management screen WITHOUT ever creating
     * or confirming a matching Payment record. Two systems of record
     * (orders.status and payments.status) could then disagree — the
     * order looked paid, but there was no Payment row to show it, no
     * paid_at timestamp, no transaction_ref. That silently broke:
     *   - PaymentController::stats()  (sums Payment.status = 'paid')
     *   - OrderController::stats()    (sums Order.status = 'paid')
     *     → these two revenue figures could diverge with no way to
     *       reconcile which one was "correct".
     *   - PaymentManagement.jsx admin screen, which would never show
     *     this order as pending/paid because no Payment row exists.
     *
     * 'paid' must only ever be reached via PaymentController::confirm()
     * or PaymentController::checkStatus() (Bakong-verified), which are
     * the single source of truth for "money has actually moved". This
     * endpoint keeps the kitchen/service workflow (pending → cooking →
     * served) and cancellation, but payment confirmation is deliberately
     * out of scope here.
     */
    private const ADMIN_UPDATABLE_STATUSES = [
        'pending', 'cooking', 'served', 'cancelled',
    ];

    private const VALID_ORDER_TYPES = [
        'dine-in', 'takeaway', 'delivery',
    ];

    private const VALID_DELIVERY_STATUSES = [
        'unassigned', 'assigned', 'picked_up', 'on_the_way', 'delivered', 'failed',
    ];

    public function __construct(
        private readonly InventoryService $inventory
    ) {}

    /**
     * Fire an OrderStatusChanged broadcast without ever letting a
     * broadcast failure turn an already-successful DB write into an
     * error response for the caller (e.g. Reverb not running).
     */
    private function safeBroadcast(Order $order, string $newStatus): void
    {
        try {
            event(new OrderStatusChanged($order, $newStatus));
        } catch (\Throwable $th) {
            Log::warning("OrderController: broadcast failed for order #{$order->id} (status: {$newStatus}) — {$th->getMessage()}");
        }
    }

    /**
     * ✅ NEW — shared helper for computing an item's effective unit price.
     *
     * ROOT CAUSE OF THE BUG THIS FIXES: the old code used
     * `$product->discount_price ?? $product->price`. PHP's `??` only
     * falls back when the left side is NULL — but legacy/bad data had
     * stored `discount_price = "0.00"` (a non-null zero) for products
     * with no real discount. Since 0.00 is not null, `??` treated it as
     * "the real discount price" and priced the item at $0.00 instead of
     * falling back to `price`. That silently zeroed out order totals
     * (see Order #40 in production: total_amount ended up $0.00).
     *
     * This mirrors the exact same "is there a valid discount" rule the
     * frontend already uses in priceUtils.js (`hasDiscount()`): a
     * discount only counts if it's > 0, strictly less than the original
     * price, AND (if an expiry is set) not yet expired. Keeping this
     * logic in one place means the backend and frontend can never
     * disagree about whether a product is "on sale" right now.
     */
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
     * Admin updates order status (kitchen/service workflow only —
     * NOT payment confirmation, see ADMIN_UPDATABLE_STATUSES above),
     * table, or notes.
     */
    public function update(Request $request, Order $order)
    {
        try {
            $validated = $request->validate([
                //  FIX: restricted to ADMIN_UPDATABLE_STATUSES instead
                // of VALID_STATUSES — 'paid' is no longer settable here.
                'status'   => 'sometimes|in:' . implode(',', self::ADMIN_UPDATABLE_STATUSES),
                'table_id' => 'nullable|exists:tables,id',
                'notes'    => 'nullable|string',
            ]);

            DB::transaction(function () use ($order, $validated) {
                if (
                    ($validated['status'] ?? null) === 'cancelled' &&
                    $order->status !== 'cancelled'
                ) {
                    // ✅ FIX: centralized via InventoryService instead of
                    // a local foreach + increment() copy-pasted here.
                    $this->inventory->restoreStock($order);
                }
                $order->update($validated);
            });

            if (isset($validated['status'])) {
                DB::afterCommit(function () use ($order, $validated) {
                    $this->safeBroadcast($order->fresh(), $validated['status']);
                });
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Order updated successfully.',
                'data'    => $order->fresh('items.product', 'table', 'user', 'rider'),
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json([
                'status'  => 'error',
                'message' => $ve->getMessage(),
                'errors'  => $ve->errors(),
            ], 422);
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
                    // ✅ FIX: centralized via InventoryService.
                    $this->inventory->restoreStock($order);
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
                if ($order->rider_id && $order->rider_id !== $validated['rider_id']) {
                    Rider::where('id', $order->rider_id)->update(['status' => 'available']);
                }

                $order->update([
                    'rider_id'        => $validated['rider_id'],
                    'delivery_status' => 'assigned',
                ]);

                Rider::where('id', $validated['rider_id'])->update(['status' => 'busy']);
            });

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
     * Customer / POS places a dine-in / takeaway / delivery order.
     *
     * FIX #1 (discount_amount): added optional POS staff discount.
     * Previously the POS screen (ManagementSaler.jsx) let staff apply a
     * manual $ or % discount and showed the discounted "grandTotal" in
     * the cart, on the printed receipt, and even fed it as the `amount`
     * shown on the generated KHQR QR code — but never sent that discount
     * to this endpoint. This endpoint always computed total_amount purely
     * from item price × quantity, so `orders.total_amount` (and, in turn,
     * `payments.amount`, since PaymentController::store() copies
     * order.total_amount) silently ended up LARGER than what the
     * customer actually saw and paid.
     *
     * FIX #2 (unit price lookup): see effectiveUnitPrice() above —
     * `discount_price ?? price` broke on legacy rows where
     * discount_price was stored as 0.00 instead of null, zeroing out
     * the item's price (and therefore the whole order total).
     *
     * The discount is validated and clamped server-side (never trusts
     * the client's pre-computed grand total) so a tampered/stale client
     * value can't under- or over-charge what's stored.
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
            'discount_amount'    => 'nullable|numeric|min:0', // ✅ POS staff discount
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

                    // ✅ FIX: was `$product->discount_price ?? $product->price`
                    // — see effectiveUnitPrice() docblock for why that broke.
                    $price        = $this->effectiveUnitPrice($product);
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

                // ✅ never trust the client-supplied discount blindly —
                // clamp it to [0, subtotal] so total_amount can't go negative
                // or exceed the real subtotal even if the frontend sends a
                // stale/tampered value.
                $discountAmount = min(
                    max((float) ($validated['discount_amount'] ?? 0), 0),
                    $totalAmount
                );

                $order = Order::create([
                    'user_id'          => auth()->id(),
                    'table_id'         => $validated['table_id'] ?? null,
                    'order_type'       => $validated['order_type'],
                    'status'           => 'pending',
                    'total_amount'     => $totalAmount - $discountAmount, // ✅ discounted total — matches what's shown/paid
                    'discount_amount'  => $discountAmount,                // ✅ kept for reporting/receipts
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
     *
     * NOTE: this is also called by the POS screen (ManagementSaler.jsx)
     * when staff abandon a KHQR scan mid-flow, and by Checkout.jsx when
     * a customer's KHQR QR expires or they navigate away — so ownership
     * here effectively covers both "customer cancels their own order"
     * and "staff cancels the order they just placed at the counter"
     * (POS orders are created with auth()->id() = the staff member).
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
                // FIX: centralized via InventoryService.
                $this->inventory->restoreStock($order);
                $order->update(['status' => 'cancelled']);
            });

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