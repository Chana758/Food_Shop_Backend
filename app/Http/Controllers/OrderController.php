<?php

namespace App\Http\Controllers;

use App\Events\OrderPaid;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rider;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use KHQR\BakongKHQR;

class OrderController extends Controller
{
    /**
     * All statuses that are considered valid on the `orders` table.
     */
    private const VALID_STATUSES = [
        'pending', 'cooking', 'served', 'paid', 'refunded', 'cancelled',
    ];

    /**
     * Statuses an admin is allowed to set directly through PUT /admin/orders/:id.
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

    /**
     * Order statuses for which stock has already left the building and must NOT be restored again.
     */
    private const STOCK_ALREADY_SETTLED_STATUSES = [
        'paid', 'refunded', 'cancelled',
    ];

    public function __construct(
        private readonly InventoryService $inventory
    ) {}

    /**
     * Reads delivery fee from config/pricing.php
     */
    private function deliveryFee(): float
    {
        return (float) config('pricing.delivery_fee', 1.00);
    }

    /**
     * Reads free delivery threshold from config/pricing.php
     */
    private function freeDeliveryThreshold(): float
    {
        return (float) config('pricing.free_delivery_threshold', 20.00);
    }

    /**
     * Safe broadcast wrapper for OrderStatusChanged event.
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
     * Calculate unit price considering valid discount conditions.
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

    /**
     * Void any pending Payment associated with an order.
     */
    private function voidPendingPayments(Order $order): void
    {
        Payment::where('order_id', $order->id)
            ->where('status', 'pending')
            ->update(['status' => 'rejected']);
    }

    // ──────────────────────────────────────────────
    //  ADMIN ENDPOINTS
    // ──────────────────────────────────────────────

    /**
     * GET /api/admin/orders
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
                ->when($request->date_from,       fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
                ->when($request->date_to,         fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
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
     */
    public function update(Request $request, Order $order)
    {
        try {
            $validated = $request->validate([
                'status'   => 'sometimes|in:' . implode(',', self::ADMIN_UPDATABLE_STATUSES),
                'table_id' => 'nullable|exists:tables,id',
                'notes'    => 'nullable|string',
            ]);

            DB::transaction(function () use ($order, $validated) {
                if (
                    ($validated['status'] ?? null) === 'cancelled' &&
                    $order->status !== 'cancelled'
                ) {
                    $this->inventory->restoreStock($order);
                    $this->voidPendingPayments($order);
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
     */
    public function destroy(Order $order)
    {
        try {
            DB::transaction(function () use ($order) {
                if (! in_array($order->status, self::STOCK_ALREADY_SETTLED_STATUSES)) {
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
     * POST /api/admin/orders/{order}/delivery-status
     *
     * ✅ CHANGED FROM PUT → POST: file uploads need multipart/form-data,
     * which browsers/axios can't reliably send over a raw PUT request.
     *
     * ✅ NEW: requires a `delivery_proof` photo before the status can be set
     * to 'delivered' — closes the trust gap where a status change was pure
     * staff self-report with no independent evidence the goods actually
     * arrived. A photo already on file (from a prior attempt) satisfies the
     * requirement without needing to re-upload.
     */
    public function updateDeliveryStatus(Request $request, Order $order)
    {
        $validated = $request->validate([
            'delivery_status' => 'required|in:' . implode(',', self::VALID_DELIVERY_STATUSES),
            'delivery_proof'  => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
        ]);

        if ($order->order_type !== 'delivery') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Only delivery orders have a delivery status.',
            ], 422);
        }

        // ✅ NEW: the actual guard — 'delivered' is refused outright without
        // photo evidence, either newly uploaded here or already on the order.
        if (
            $validated['delivery_status'] === 'delivered'
            && ! $order->delivery_proof
            && ! $request->hasFile('delivery_proof')
        ) {
            return response()->json([
                'status'  => 'error',
                'message' => 'A delivery photo is required before marking as delivered.',
            ], 422);
        }

        try {
            $proofPath = null;

            DB::transaction(function () use ($order, $validated, $request, &$proofPath) {
                if ($request->hasFile('delivery_proof')) {
                    $proofPath = $request->file('delivery_proof')->store('deliveries/proofs', 'public');
                    $order->delivery_proof = $proofPath;
                }

                $order->delivery_status = $validated['delivery_status'];
                $order->save();

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
     * Standard order creation (Cash / COD / Takeaway / Dine-in).
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
            'discount_amount'    => 'nullable|numeric|min:0',
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

                $discountAmount = min(
                    max((float) ($validated['discount_amount'] ?? 0), 0),
                    $totalAmount
                );

                $deliveryFee = ($validated['order_type'] === 'delivery'
                    && $totalAmount > 0
                    && $totalAmount < $this->freeDeliveryThreshold())
                    ? $this->deliveryFee()
                    : 0;

                $finalTotal = round($totalAmount - $discountAmount + $deliveryFee, 2);

                $order = Order::create([
                    'user_id'          => auth()->id(),
                    'table_id'         => $validated['table_id'] ?? null,
                    'order_type'       => $validated['order_type'],
                    'status'           => 'pending',
                    'total_amount'     => $finalTotal,
                    'discount_amount'  => $discountAmount,
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
     * POST /api/orders/khqr
     * Atomic KHQR Payment Verification & Order Placement.
     */
    public function storeIfKhqrPaid(Request $request)
    {
        $validated = $request->validate([
            'order_type'         => 'required|in:' . implode(',', self::VALID_ORDER_TYPES),
            'notes'              => 'nullable|string',
            'customer_name'      => 'required_if:order_type,delivery|nullable|string|max:255',
            'customer_phone'     => 'required_if:order_type,delivery|nullable|string|max:20',
            'delivery_address'   => 'required_if:order_type,delivery|nullable|string',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'transaction_ref'    => 'required|string',
        ]);

        try {
            // 1. Idempotency Guard
            $existing = Payment::where('transaction_ref', $validated['transaction_ref'])
                ->where('status', 'paid')
                ->first();

            if ($existing) {
                return response()->json([
                    'status' => 'success',
                    'paid'   => true,
                    'data'   => $existing->order->load('items.product', 'table', 'user', 'rider'),
                ], 200);
            }

            // 2. Server-side Amount Calculation
            $totalAmount = 0;
            foreach ($validated['items'] as $item) {
                $product = Product::find($item['product_id']);
                if (! $product) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => "Product #{$item['product_id']} not found.",
                    ], 422);
                }
                $totalAmount += $this->effectiveUnitPrice($product) * $item['quantity'];
            }

            $deliveryFee = ($validated['order_type'] === 'delivery'
                && $totalAmount > 0
                && $totalAmount < $this->freeDeliveryThreshold())
                ? $this->deliveryFee()
                : 0;

            $finalTotal = round($totalAmount + $deliveryFee, 2);

            // 3. Verify with Bakong KHQR Open API
            $bakong = new BakongKHQR(config('services.bakong.token'));
            $result = $bakong->checkTransactionByMD5($validated['transaction_ref']);

            $responseCode    = $result->responseCode ?? ($result->status->code ?? null);
            $transactionData = $result->data ?? null;

            $amountMatches = $transactionData
                && isset($transactionData->amount)
                && abs((float) $transactionData->amount - $finalTotal) < 0.01;

            if ($responseCode !== 0 || ! $transactionData || ! $amountMatches) {
                return response()->json(['status' => 'success', 'paid' => false], 200);
            }

            // 4. Create Order & Payment atomically upon payment confirmation
            $order = DB::transaction(function () use ($validated, $finalTotal) {
                $itemsData = [];

                foreach ($validated['items'] as $item) {
                    $product = Product::lockForUpdate()->find($item['product_id']);

                    if ($product->stock_quantity < $item['quantity']) {
                        throw new \Exception("Insufficient stock for: {$product->name}.");
                    }

                    $price       = $this->effectiveUnitPrice($product);
                    $itemsData[] = [
                        'product_id' => $product->id,
                        'price'      => $price,
                        'quantity'   => $item['quantity'],
                        'subtotal'   => $price * $item['quantity'],
                    ];

                    $product->decrement('stock_quantity', $item['quantity']);
                }

                $order = Order::create([
                    'user_id'          => auth()->id(),
                    'order_type'       => $validated['order_type'],
                    'status'           => 'paid',
                    'total_amount'     => $finalTotal,
                    'discount_amount'  => 0,
                    'notes'            => $validated['notes'] ?? null,
                    'customer_name'    => $validated['customer_name']    ?? null,
                    'customer_phone'   => $validated['customer_phone']   ?? null,
                    'delivery_address' => $validated['delivery_address'] ?? null,
                    'delivery_status'  => $validated['order_type'] === 'delivery' ? 'unassigned' : null,
                ]);

                foreach ($itemsData as $data) {
                    $order->items()->create($data);
                }

                Payment::create([
                    'order_id'        => $order->id,
                    'user_id'         => auth()->id(),
                    'amount'          => $finalTotal,
                    'method'          => 'khqr',
                    'status'          => 'paid',
                    'transaction_ref' => $validated['transaction_ref'],
                    'paid_at'         => now(),
                ]);

                return $order;
            });

            DB::afterCommit(function () use ($order) {
                try {
                    event(new OrderPaid($order->fresh('items.product', 'table', 'user', 'rider')));
                } catch (\Throwable $th) {
                    Log::warning("storeIfKhqrPaid: broadcast failed #{$order->id} — {$th->getMessage()}");
                }
            });

            return response()->json([
                'status' => 'success',
                'paid'   => true,
                'data'   => $order->load('items.product', 'table', 'user', 'rider'),
            ], 201);

        } catch (\Throwable $th) {
            Log::error('OrderController@storeIfKhqrPaid: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * GET /api/orders
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
                $this->inventory->restoreStock($order);
                $order->update(['status' => 'cancelled']);
                $this->voidPendingPayments($order);
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
     */
    public function stats()
    {
        try {
            $today = \Carbon\Carbon::today('Asia/Phnom_Penh');

            $stats = [
                'by_status' => Order::selectRaw('status, COUNT(*) as count')
                    ->groupBy('status')->get(),

                'by_type' => Order::selectRaw('order_type, COUNT(*) as count, SUM(total_amount) as revenue')
                    ->groupBy('order_type')->get(),

                'by_delivery_status' => Order::where('order_type', 'delivery')
                    ->selectRaw('delivery_status, COUNT(*) as count')
                    ->groupBy('delivery_status')->get(),

                'today' => [
                    'orders'  => Order::whereDate('created_at', $today)->count(),
                    'revenue' => Order::whereDate('created_at', $today)
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