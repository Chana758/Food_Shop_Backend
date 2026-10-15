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
    // Valid order statuses
    private const VALID_STATUSES = [
        'pending', 'cooking', 'served', 'paid', 'refunded', 'cancelled',
    ];

    // Statuses admin can set directly
    private const ADMIN_UPDATABLE_STATUSES = [
        'pending', 'cooking', 'served', 'cancelled',
    ];

    private const VALID_ORDER_TYPES = [
        'dine-in', 'takeaway', 'delivery',
    ];

    private const VALID_DELIVERY_STATUSES = [
        'unassigned', 'assigned', 'picked_up', 'on_the_way', 'delivered', 'failed',
    ];

    // Stock already settled, do not restore
    private const STOCK_ALREADY_SETTLED_STATUSES = [
        'paid', 'refunded', 'cancelled',
    ];

    private const FINANCIALLY_SETTLED_STATUSES = [
        'paid', 'refunded',
    ];

    private const STOCK_SHORTAGE_NOTE_PREFIX = '⚠️ STOCK SHORTAGE — verify fulfillment: ';

    public function __construct(
        private readonly InventoryService $inventory
    ) {}

    // Delivery fee from config/pricing.php
    private function deliveryFee(): float
    {
        return (float) config('pricing.delivery_fee', 1.00);
    }

    // Free delivery threshold from config/pricing.php
    private function freeDeliveryThreshold(): float
    {
        return (float) config('pricing.free_delivery_threshold', 20.00);
    }

    // Broadcast OrderStatusChanged without failing the request
    private function safeBroadcast(Order $order, string $newStatus): void
    {
        try {
            event(new OrderStatusChanged($order, $newStatus));
        } catch (\Throwable $th) {
            Log::warning("OrderController: broadcast failed for order #{$order->id} (status: {$newStatus}) — {$th->getMessage()}");
        }
    }

    // Unit price with valid discount applied
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

    // Reject pending payments of an order
    private function voidPendingPayments(Order $order): void
    {
        Payment::where('order_id', $order->id)
            ->where('status', 'pending')
            ->update(['status' => 'rejected']);
    }

    // Admin endpoints

    // Admin: list orders with filters, newest first.
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

    // Show one order (owner or admin).
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

    // Admin: update status, table or notes (cancel restores stock).
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

    // Admin: delete an order (blocked once paid/refunded, use refund).
    public function destroy(Order $order)
    {
        if (in_array($order->status, self::FINANCIALLY_SETTLED_STATUSES)) {
            return response()->json([
                'status'  => 'error',
                'message' => "Cannot delete a {$order->status} order — it has an associated payment record. Use refund instead.",
            ], 422);
        }

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

    // Admin: assign a rider to a delivery order (rider must not be busy).
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

        $rider = Rider::find($validated['rider_id']);

        // Same rider can be re-assigned; a different busy rider is blocked
        if (
            $rider
            && $rider->status === 'busy'
            && $order->rider_id !== $rider->id
        ) {
            return response()->json([
                'status'  => 'error',
                'message' => "{$rider->name} is currently busy on another delivery.",
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

    // Admin: update delivery status (photo required for delivered).
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

    // Customer endpoints

    // Create an order and deduct stock (total includes tax to match the KHQR amount).
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
            'tax_amount'         => 'nullable|numeric|min:0',
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

                // Tax applied after discount
                $taxAmount = max((float) ($validated['tax_amount'] ?? 0), 0);

                $deliveryFee = ($validated['order_type'] === 'delivery'
                    && $totalAmount > 0
                    && $totalAmount < $this->freeDeliveryThreshold())
                    ? $this->deliveryFee()
                    : 0;

                $finalTotal = round($totalAmount - $discountAmount + $deliveryFee + $taxAmount, 2);

                $order = Order::create([
                    'user_id'          => auth()->id(),
                    'table_id'         => $validated['table_id'] ?? null,
                    'order_type'       => $validated['order_type'],
                    'status'           => 'pending',
                    'total_amount'     => $finalTotal,
                    'discount_amount'  => $discountAmount,
                    'tax_amount'       => $taxAmount,
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

    // Create a paid order once Bakong confirms KHQR payment (lock per ref; stock never rolls back Order + Payment).
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

        $ref      = $validated['transaction_ref'];
        $lockName = 'khqr_ref_' . $ref;
        // Named lock per transaction_ref (waits up to 10s)
        $gotLock = DB::selectOne('SELECT GET_LOCK(?, 10) as locked', [$lockName])->locked;

        if (! $gotLock) {
            Log::warning("storeIfKhqrPaid: could not acquire lock for ref {$ref} — treating as concurrent duplicate request.");
            $existing = Payment::where('transaction_ref', $ref)->where('status', 'paid')->first();
            if ($existing) {
                return response()->json([
                    'status' => 'success',
                    'paid'   => true,
                    'data'   => $existing->order->load('items.product', 'table', 'user', 'rider'),
                ], 200);
            }
            return response()->json(['status' => 'success', 'paid' => false], 200);
        }

        try {
            // 1. Idempotency guard
            $existing = Payment::where('transaction_ref', $ref)
                ->where('status', 'paid')
                ->first();

            if ($existing) {
                return response()->json([
                    'status' => 'success',
                    'paid'   => true,
                    'data'   => $existing->order->load('items.product', 'table', 'user', 'rider'),
                ], 200);
            }

            // 2. Calculate amount server-side
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
            $result = $bakong->checkTransactionByMD5($ref);

            $responseCode    = $result->responseCode ?? ($result->status->code ?? null);
            $transactionData = $result->data ?? null;

            $amountMatches = $transactionData
                && isset($transactionData->amount)
                && abs((float) $transactionData->amount - $finalTotal) < 0.01;

            Log::info('Bakong storeIfKhqrPaid debug', [
                'transaction_ref' => $ref,
                'response_code'   => $responseCode,
                'bakong_amount'   => $transactionData->amount ?? null,
                'bakong_currency' => $transactionData->currency ?? null,
                'expected_amount' => $finalTotal,
                'amount_matches'  => $amountMatches,
                'has_data'        => (bool) $transactionData,
            ]);

            if ($responseCode !== 0 || ! $transactionData || ! $amountMatches) {
                Log::warning('Bakong storeIfKhqrPaid: not marked paid', [
                    'transaction_ref'  => $ref,
                    'response_code_ok' => $responseCode === 0,
                    'has_data'         => (bool) $transactionData,
                    'amount_matches'   => $amountMatches,
                ]);
                return response()->json(['status' => 'success', 'paid' => false], 200);
            }

            // 4. Bakong confirmed payment: Order + Payment must always be saved

            $shortages = [];

            $order = DB::transaction(function () use ($validated, $finalTotal, $ref, &$shortages) {

                // 4a. Create Order + Payment first (never rolled back by stock)
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

                Payment::create([
                    'order_id'        => $order->id,
                    'user_id'         => auth()->id(),
                    'amount'          => $finalTotal,
                    'method'          => 'khqr',
                    'status'          => 'paid',
                    'transaction_ref' => $ref,
                    'paid_at'         => now(),
                ]);

                // 4b. Best-effort stock decrement (clamp at 0, never throws)
                foreach ($validated['items'] as $item) {
                    $product = Product::lockForUpdate()->find($item['product_id']);

                    $price        = $product ? $this->effectiveUnitPrice($product) : 0;
                    $requestedQty = $item['quantity'];
                    $availableQty = $product ? max(0, (int) $product->stock_quantity) : 0;

                    if (! $product || $availableQty < $requestedQty) {
                        $shortages[] = [
                            'product_id'   => $item['product_id'],
                            'product_name' => $product->name ?? "#{$item['product_id']}",
                            'requested'    => $requestedQty,
                            'available'    => $availableQty,
                        ];
                    }

                    $order->items()->create([
                        'product_id' => $item['product_id'],
                        'price'      => $price,
                        'quantity'   => $requestedQty,
                        'subtotal'   => $price * $requestedQty,
                    ]);

                    if ($product) {
                        // Clamp at 0
                        $product->update([
                            'stock_quantity' => max(0, $availableQty - $requestedQty),
                        ]);
                    }
                }

                // 4c. Flag order if stock was short
                if (! empty($shortages)) {
                    $summary = collect($shortages)
                        ->map(fn($s) => "{$s['product_name']} (need {$s['requested']}, had {$s['available']})")
                        ->implode('; ');

                    $order->update([
                        'notes' => self::STOCK_SHORTAGE_NOTE_PREFIX . $summary
                            . ($order->notes ? " | {$order->notes}" : ''),
                    ]);
                }

                return $order;
            });

            if (! empty($shortages)) {
                // Separate critical alert for oversold stock
                Log::critical('storeIfKhqrPaid: order paid but stock oversold', [
                    'order_id'        => $order->id,
                    'transaction_ref' => $ref,
                    'shortages'       => $shortages,
                ]);
            }

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
            // Last resort: log ref for manual reconciliation
            Log::critical('storeIfKhqrPaid: unexpected failure AFTER Bakong may have confirmed payment — manual reconciliation may be required', [
                'transaction_ref' => $ref,
                'error'           => $th->getMessage(),
            ]);
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        } finally {
            // Always release the lock
            DB::statement('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    // List my orders, newest first.
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

    // Cancel my pending order and restore stock.
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

    // Admin: order stats by status, type, today, month and top products.
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