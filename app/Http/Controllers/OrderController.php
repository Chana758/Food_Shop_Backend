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

    private const FINANCIALLY_SETTLED_STATUSES = [
        'paid', 'refunded',
    ];

    private const STOCK_SHORTAGE_NOTE_PREFIX = '⚠️ STOCK SHORTAGE — verify fulfillment: ';

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
     *
     * Refuses to delete an order once it's financially settled
     * ('paid' or 'refunded'). Previously this only skipped restoring
     * stock for those statuses but still allowed the delete to proceed —
     * and because `payments.order_id` cascades on delete, that silently
     * destroyed the matching Payment row too, with no trace that money
     * ever changed hands. Use PaymentController::refund() to reverse a
     * paid order instead of deleting it.
     */
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

    /**
     * PUT /api/admin/orders/{order}/assign-rider
     *
     * Refuses to assign a rider who is already 'busy' on another
     * delivery. Previously only the order's own delivery_status was
     * checked, so the same rider could be double-booked onto two
     * deliveries at once.
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

        $rider = Rider::find($validated['rider_id']);

        // Allow re-assigning the SAME rider back onto this order
        // (e.g. re-confirming), but block picking a DIFFERENT rider who is
        // currently busy on some other delivery.
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

    /**
     * POST /api/admin/orders/{order}/delivery-status
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
     *
     * ✅ FIX (Bug 1): total_amount now includes tax_amount, computed
     * server-side from the same figure the client (POS / storefront)
     * displays and — for KHQR — encodes into the QR code. Previously
     * total_amount = subtotal - discount + delivery_fee only, with NO
     * tax term at all, while ManagementSaler.jsx's grandTotal (used to
     * generate the POS KHQR QR) DID include tax whenever Settings >
     * Receipt & POS Rules had tax enabled. That mismatch meant
     * PaymentController::checkStatus()'s `amountMatches` check
     * (comparing Bakong's reported paid amount against
     * payments.amount, which is copied from orders.total_amount) could
     * never succeed for a taxed order — the customer would scan and
     * genuinely pay, but the system would never detect it as paid.
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
            'tax_amount'         => 'nullable|numeric|min:0', // ✅ NEW
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

                // ✅ NEW: tax, applied after discount. Clamped at 0 minimum;
                // no upper bound needed since it's server-trusted-but-client-
                // supplied — see note above re: KHQR amount matching.
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
                    'tax_amount'       => $taxAmount, // ✅ NEW
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
     *
     * Closes a race condition. Because this endpoint is polled every
     * ~4s and can be hit concurrently (two browser tabs, a retry racing the
     * original request, etc.), two requests could previously both pass the
     * "does a paid Payment already exist for this transaction_ref" check
     * before either had written anything — the check-then-act gap wasn't
     * protected by any lock — and each would go on to create its own
     * duplicate Order + Payment for the same real-world transaction.
     *
     * Fix: acquire a MySQL named lock keyed on the transaction_ref for the
     * duration of the Bakong verification + order-creation, so only one
     * request can be "in flight" for a given transaction_ref at a time.
     * The second concurrent request waits, then hits the idempotency guard
     * (which now finds the just-created Payment) and returns it instead of
     * creating a second one.
     *
     * ✅ FIX (money-safety bug): once Bakong confirms `paid: true`, real
     * money has ALREADY left the customer's wallet — that fact can no
     * longer be undone by anything that happens on our side afterward.
     * The old code still ran the stock check *inside* the same
     * DB::transaction() as the Order/Payment creation, so an
     * "Insufficient stock" exception rolled back EVERYTHING, including
     * the Payment row — leaving no record anywhere in the system that a
     * real, Bakong-confirmed payment had happened. The customer would see
     * a generic error (or the QR simply "expiring"), while the money sat
     * in the merchant's ABA account with no matching order, and no way
     * for an admin to reconcile it.
     *
     * Fix: Order + Payment creation is now unconditional once Bakong
     * confirms payment — it can NEVER be rolled back by a stock issue.
     * Stock is decremented on a best-effort basis in a SEPARATE step
     * after the financial record already exists; any item that runs
     * short is clamped at 0 (never negative) and the order is flagged
     * with a human-visible note + a Log::critical() alert so staff catch
     * it immediately instead of the sale vanishing untraceably.
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

        $ref      = $validated['transaction_ref'];
        $lockName = 'khqr_ref_' . $ref;
        // getLock() with a short timeout: if another request already holds
        // the lock for this exact transaction_ref, wait briefly for it to
        // finish (it's doing the same work) rather than racing it.
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
            // 1. Idempotency Guard (now safe from the race: only one
            //    request at a time reaches this point for a given ref)
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

            // 2. Server-side Amount Calculation (pricing only — no stock
            //    mutation here, so a mispriced/missing product is still
            //    safe to reject before any money is claimed as ours).
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

            // ─────────────────────────────────────────────────────────
            // 4. FROM HERE ON, BAKONG HAS CONFIRMED REAL MONEY MOVED.
            //    Everything below MUST result in a persisted Order +
            //    Payment — nothing past this point is allowed to roll
            //    that back. Stock shortage is handled as a soft,
            //    best-effort, non-fatal step (4b), never as a reason to
            //    lose the financial record (4a).
            // ─────────────────────────────────────────────────────────

            $shortages = [];

            $order = DB::transaction(function () use ($validated, $finalTotal, $ref, &$shortages) {

                // 4a. Create the Order + Payment FIRST, unconditionally.
                //     This is the money-safety guarantee: no exception
                //     thrown afterward (e.g. from stock handling) can
                //     unwind this, because stock handling below never
                //     throws — see 4b.
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

                // 4b. Best-effort stock decrement. Never throws: an item
                //     running short is clamped at 0 and recorded in
                //     $shortages for the caller to flag, instead of
                //     aborting the (already-financially-real) order.
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
                        // Clamp at 0 — never go negative, never throw.
                        $product->update([
                            'stock_quantity' => max(0, $availableQty - $requestedQty),
                        ]);
                    }
                }

                // 4c. Flag the order for staff review if anything came up short.
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
                // Loud, separate alert — this must never blend in with
                // routine info/warning logs. A paid order shipped with
                // an oversold item is a same-day operational issue.
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
            // NOTE: by the time we could reach here, Bakong may already
            // have confirmed payment (step 3) but the Order/Payment
            // creation itself (step 4a) failed for some *other* reason
            // (DB outage, etc.) — in that rare case the transaction_ref
            // is still safely logged below so it can be reconciled
            // manually. This is a last-resort net, not a substitute for
            // 4a/4b succeeding normally.
            Log::critical('storeIfKhqrPaid: unexpected failure AFTER Bakong may have confirmed payment — manual reconciliation may be required', [
                'transaction_ref' => $ref,
                'error'           => $th->getMessage(),
            ]);
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        } finally {
            // Always release the lock, even on exception/early return.
            DB::statement('SELECT RELEASE_LOCK(?)', [$lockName]);
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