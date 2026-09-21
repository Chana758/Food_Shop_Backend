<?php

namespace App\Http\Controllers;

use App\Events\OrderPaid;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use KHQR\BakongKHQR;

class PaymentController extends Controller
{
    // ──────────────────────────────────────────────
    //  ADMIN ENDPOINTS
    // ──────────────────────────────────────────────

    public function index(Request $request)
    {
        try {
            $payments = Payment::with(['order.items.product', 'user'])
                ->when($request->status,  fn($q) => $q->where('status',  $request->status))
                ->when($request->method,  fn($q) => $q->where('method',  $request->method))
                ->when($request->user_id, fn($q) => $q->where('user_id', $request->user_id))
                ->when($request->date_from, fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
                ->when($request->date_to,   fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
                ->latest()
                ->paginate($request->per_page ?? 15);

            return response()->json(['status' => 'success', 'data' => $payments], 200);
        } catch (\Throwable $th) {
            Log::error('PaymentController@index: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    public function show(Payment $payment)
    {
        return response()->json([
            'status' => 'success',
            'data'   => $payment->load('order.items.product', 'user'),
        ], 200);
    }

    /**
     * PUT /api/admin/payments/{payment}/confirm
     *
     * Added an $alreadyPaid guard, mirroring checkStatus() below, so a
     * double-click / retry never re-broadcasts OrderPaid for a payment
     * that was already confirmed.
     */
    public function confirm(Payment $payment)
    {
        if ($payment->status !== 'pending') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Only pending payments can be confirmed. Current status: ' . $payment->status,
            ], 422);
        }

        try {
            $alreadyPaid = false;

            DB::transaction(function () use ($payment, &$alreadyPaid) {
                $locked = Payment::lockForUpdate()->findOrFail($payment->id);

                if ($locked->status === 'paid') {
                    $alreadyPaid = true;
                    return;
                }

                $locked->update(['status' => 'paid', 'paid_at' => now()]);
                $locked->order->update(['status' => 'paid']);
            });

            if (! $alreadyPaid) {
                DB::afterCommit(function () use ($payment) {
                    event(new OrderPaid($payment->order->fresh()));
                });
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment confirmed successfully.',
                'data'    => $payment->fresh('order', 'user'),
            ], 200);
        } catch (\Throwable $th) {
            Log::error('PaymentController@confirm: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    public function reject(Payment $payment)
    {
        if ($payment->status !== 'pending') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Only pending payments can be rejected. Current status: ' . $payment->status,
            ], 422);
        }

        try {
            DB::transaction(function () use ($payment) {
                $payment->update(['status' => 'rejected']);
                $payment->order->update(['status' => 'cancelled']);
            });

            DB::afterCommit(function () use ($payment) {
                event(new OrderStatusChanged($payment->order->fresh(), 'cancelled'));
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment rejected.',
                'data'    => $payment->fresh('order', 'user'),
            ], 200);
        } catch (\Throwable $th) {
            Log::error('PaymentController@reject: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    public function refund(Payment $payment)
    {
        if ($payment->status !== 'paid') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Only paid payments can be refunded. Current status: ' . $payment->status,
            ], 422);
        }

        try {
            DB::transaction(function () use ($payment) {
                $payment->update(['status' => 'refunded']);
                $payment->order->update(['status' => 'refunded']);
            });

            DB::afterCommit(function () use ($payment) {
                event(new OrderStatusChanged($payment->order->fresh(), 'refunded'));
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment refunded successfully.',
                'data'    => $payment->fresh('order', 'user'),
            ], 200);
        } catch (\Throwable $th) {
            Log::error('PaymentController@refund: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    public function destroy(Payment $payment)
    {
        if ($payment->status === 'paid') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cannot delete a paid payment. Use refund instead.',
            ], 422);
        }

        try {
            if ($payment->receipt_image) {
                Storage::disk('public')->delete($payment->receipt_image);
            }
            $payment->delete();

            return response()->json(['status' => 'success', 'message' => 'Payment deleted successfully.'], 200);
        } catch (\Throwable $th) {
            Log::error('PaymentController@destroy: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    // ──────────────────────────────────────────────
    //  CUSTOMER / STAFF ENDPOINTS
    // ──────────────────────────────────────────────

    /**
     * POST /api/payments
     *
     * Ownership guard (IDOR fix): the order must belong to the requester,
     * unless they're admin/staff (who legitimately create payments on
     * behalf of walk-in / POS customers).
     *
     * Wraps file upload + Payment::create() in DB::transaction so a
     * failed create() doesn't leave an orphaned file in storage.
     *
     * Any old PENDING payment for the same order is voided (status ->
     * 'rejected') before a fresh one is created, so "Generate New QR"
     * retries never get stuck behind a stale row — only a PAID payment
     * blocks a new one.
     *
     * ✅ NEW (Bug 2 fix): `paid_now` — lets a staff/admin caller mark a
     * cash or card payment as 'paid' immediately at creation time,
     * instead of always creating it as 'pending'.
     *
     * ROOT CAUSE THIS FIXES: this endpoint always created Payment rows
     * as status='pending', which is correct for the storefront's Cash-
     * on-Delivery flow (Checkout.jsx) — the rider hasn't collected
     * money yet. But ManagementSaler.jsx (the in-store POS) calls this
     * SAME endpoint for Cash and Card sales, where the cashier has
     * ALREADY physically collected the money at the register the
     * instant the sale is rung up. Because every POS cash/card payment
     * stayed 'pending' forever (nothing in the POS flow ever called
     * confirm()), two things broke:
     *   1. order.status never advanced to 'paid', so those sales never
     *      counted in Order::stats()/PaymentController::stats() revenue
     *      totals (both filter on status = 'paid').
     *   2. Every single POS cash/card sale piled up in the admin
     *      "Payment Management" pending queue, indistinguishable from a
     *      KHQR receipt that genuinely needs a human to verify it —
     *      requiring an admin to manually click "Confirm" on every POS
     *      sale, which isn't viable at register speed.
     *
     * Restricted to admin/staff AND method cash/card only — KHQR must
     * always go through Bakong verification (checkStatus/
     * storeIfKhqrPaid), never a client-asserted "trust me, it's paid"
     * flag, or anyone could fake a paid transaction.
     *
     * `amount` is copied straight from `order.total_amount`, which (as
     * of OrderController::store()'s fix) now correctly includes both
     * the delivery fee for delivery orders AND tax where enabled. This
     * field is what checkStatus() below compares against Bakong's
     * reported paid amount — keeping it accurate here is what makes
     * that verification meaningful at all.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id'        => 'required|exists:orders,id',
            'method'          => 'required|in:cash,khqr,card',
            'transaction_ref' => 'nullable|string|unique:payments,transaction_ref',
            'receipt_image'   => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
            'paid_now'        => 'sometimes|boolean', // ✅ NEW
        ]);

        $receiptPath = null;

        try {
            $order = Order::findOrFail($validated['order_id']);

            // Ownership guard (IDOR fix).
            $user = auth()->user();
            if ($order->user_id !== $user->id && ! in_array($user->role, ['admin', 'staff'])) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Unauthorized',
                ], 403);
            }

            $alreadyPaid = Payment::where('order_id', $order->id)
                ->where('status', 'paid')
                ->exists();

            if ($alreadyPaid) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'This order has already been paid.',
                ], 422);
            }

            // Void stale pending payments instead of blocking retries.
            // This intentionally happens OUTSIDE the create transaction below —
            // even if create() subsequently fails, we don't want to resurrect
            // a stale pending row as "the" payment for this order.
            Payment::where('order_id', $order->id)
                ->where('status', 'pending')
                ->update(['status' => 'rejected']);

            // ✅ NEW: only a staff/admin can mark a payment paid at creation
            // time, and only for cash/card — money physically collected at
            // the register. KHQR must always go through Bakong verification.
            $markPaidNow = in_array($user->role, ['admin', 'staff'])
                && ($validated['paid_now'] ?? false)
                && in_array($validated['method'], ['cash', 'card']);

            $payment = DB::transaction(function () use ($request, $validated, $order, &$receiptPath, $markPaidNow) {
                if ($request->hasFile('receipt_image')) {
                    $receiptPath = $request->file('receipt_image')->store('payments/receipts', 'public');
                }

                $payment = Payment::create([
                    'order_id'        => $order->id,
                    'user_id'         => auth()->id(),
                    // order.total_amount already reflects any staff/POS
                    // discount applied at order-creation time, tax (see
                    // OrderController::store()), AND the delivery fee for
                    // delivery orders — so this always matches what the
                    // customer is actually shown/charged.
                    'amount'          => $order->total_amount,
                    'method'          => $validated['method'],
                    'status'          => $markPaidNow ? 'paid' : 'pending',
                    'transaction_ref' => $validated['transaction_ref'] ?? null,
                    'receipt_image'   => $receiptPath,
                    'paid_at'         => $markPaidNow ? now() : null,
                ]);

                if ($markPaidNow) {
                    $order->update(['status' => 'paid']);
                }

                return $payment;
            });

            if ($markPaidNow) {
                DB::afterCommit(function () use ($payment) {
                    event(new OrderPaid($payment->order->fresh()));
                });
            }

            return response()->json([
                'status' => 'success',
                'data'   => $payment->load('order', 'user'),
            ], 201);
        } catch (\Throwable $th) {
            // if Payment::create() fails after a file was already
            // uploaded, delete it so it doesn't linger as an orphan in storage.
            if ($receiptPath) {
                Storage::disk('public')->delete($receiptPath);
            }
            Log::error('PaymentController@store: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * POST /api/payments/{payment}/check-status
     *
     * A KHQR "dynamic QR" is generated entirely CLIENT-SIDE (see
     * utils/khqr.js — `KHQR.generate()` runs in the browser, no server
     * round-trip to Bakong at all). Bakong's `checkTransactionByMD5`
     * endpoint returns `responseCode: 0` simply to mean "the API request
     * itself was processed successfully" — it returns that same code 0
     * whether or not a matching PAID transaction actually exists yet.
     * Whether a real payment was found is indicated by the `data` field:
     * null/empty means "nothing paid yet", a populated object means
     * "found it".
     *
     * Only treat it as paid when Bakong's `data` is actually present
     * AND its reported amount matches what we expect (`payments.amount`).
     * The amount check additionally guards against a stale/reused MD5
     * ever confirming the wrong order.
     *
     * Diagnostic logging added around every branch of this method so
     * that a "customer paid but system never saw it" report can be
     * root-caused from storage/logs/laravel.log alone.
     */
    public function checkStatus(Payment $payment)
    {
        if ($payment->user_id !== auth()->id() && !in_array(auth()->user()->role, ['admin', 'staff'])) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        if ($payment->status === 'paid') {
            return response()->json([
                'status' => 'success',
                'paid'   => true,
                'data'   => $payment->load('order'),
            ]);
        }

        if (! $payment->transaction_ref) {
            return response()->json([
                'status'  => 'success',
                'paid'    => false,
                'message' => 'No transaction reference — awaiting admin confirmation.',
            ]);
        }

        try {
            $bakong = new BakongKHQR(config('services.bakong.token'));
            $result = $bakong->checkTransactionByMD5($payment->transaction_ref);

            $responseCode = $result->responseCode ?? ($result->status->code ?? null);

            // `responseCode === 0` only means the API call succeeded — it
            // does NOT mean a paid transaction was found. Require an
            // actual transaction payload before trusting it.
            $transactionData = $result->data ?? null;

            // Extra safety: confirm the amount Bakong reports actually
            // matches this payment, so a stale/reused MD5 (or a QR
            // regenerated for a different, unrelated order sharing an
            // amount) can never confirm the wrong payment.
            $amountMatches = $transactionData
                && isset($transactionData->amount)
                && abs((float) $transactionData->amount - (float) $payment->amount) < 0.01;

            Log::info('Bakong checkStatus debug', [
                'payment_id'      => $payment->id,
                'transaction_ref' => $payment->transaction_ref,
                'response_code'   => $responseCode,
                'bakong_amount'   => $transactionData->amount ?? null,
                'bakong_currency' => $transactionData->currency ?? null,
                'expected_amount' => (float) $payment->amount,
                'amount_matches'  => $amountMatches,
                'has_data'        => (bool) $transactionData,
            ]);

            if ($responseCode === 0 && $transactionData && $amountMatches) {
                $alreadyPaid = false;

                DB::transaction(function () use ($payment, &$alreadyPaid) {
                    $locked = Payment::lockForUpdate()->findOrFail($payment->id);

                    if ($locked->status === 'paid') {
                        $alreadyPaid = true;
                        return;
                    }

                    $locked->update(['status' => 'paid', 'paid_at' => now()]);
                    $locked->order->update(['status' => 'paid']);
                });

                if (! $alreadyPaid) {
                    DB::afterCommit(function () use ($payment) {
                        event(new OrderPaid($payment->order->fresh()));
                    });
                }

                return response()->json([
                    'status' => 'success',
                    'paid'   => true,
                    'data'   => $payment->fresh('order'),
                ]);
            }

            Log::warning('Bakong checkStatus: not marked paid', [
                'payment_id'       => $payment->id,
                'response_code_ok' => $responseCode === 0,
                'has_data'         => (bool) $transactionData,
                'amount_matches'   => $amountMatches,
            ]);

            return response()->json(['status' => 'success', 'paid' => false]);
        } catch (\Throwable $th) {
            Log::error('Bakong checkStatus failed', [
                'payment_id' => $payment->id,
                'message'    => $th->getMessage(),
                'trace'      => $th->getTraceAsString(),
            ]);

            return response()->json([
                'status'  => 'success',
                'paid'    => false,
                'message' => 'Bakong API unavailable — admin can confirm manually.',
            ]);
        }
    }

    public function myPayments(Request $request)
    {
        try {
            $payments = Payment::with(['order.items.product'])
                ->where('user_id', auth()->id())
                ->when($request->status, fn($q) => $q->where('status', $request->status))
                ->latest()
                ->paginate($request->per_page ?? 10);

            return response()->json(['status' => 'success', 'data' => $payments], 200);
        } catch (\Throwable $th) {
            Log::error('PaymentController@myPayments: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    public function uploadReceipt(Request $request, Payment $payment)
    {
        if ($payment->user_id !== auth()->id()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        if ($payment->status !== 'pending') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Receipt can only be uploaded for pending payments.',
            ], 422);
        }

        $request->validate([
            'receipt_image' => 'required|image|mimes:jpg,jpeg,png|max:5120',
        ]);

        try {
            if ($payment->receipt_image) {
                Storage::disk('public')->delete($payment->receipt_image);
            }

            $path = $request->file('receipt_image')->store('payments/receipts', 'public');
            $payment->update(['receipt_image' => $path]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Receipt uploaded successfully.',
                'data'    => $payment->fresh(),
            ], 200);
        } catch (\Throwable $th) {
            Log::error('PaymentController@uploadReceipt: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }


    public function stats()
    {
        try {
            $stats = [
                'total_revenue'  => Payment::where('status', 'paid')->sum('amount'),
                'total_refunded' => Payment::where('status', 'refunded')->sum('amount'),

                'by_status' => Payment::selectRaw('status, COUNT(*) as count, SUM(amount) as total')
                    ->groupBy('status')->get(),

                'by_method' => Payment::selectRaw('method, COUNT(*) as count, SUM(amount) as total')
                    ->where('status', 'paid')->groupBy('method')->get(),

                // NOTE: still excludes cash — cash legitimately stays
                // 'pending' until an admin/rider confirms delivery for
                // COD orders. With the paid_now fix, POS cash/card sales
                // no longer sit here at all (they're created already
                // 'paid'), so this count now accurately reflects only
                // KHQR/card payments genuinely awaiting human review.
                'pending_confirmation_count' => Payment::where('status', 'pending')
                    ->where('method', '!=', 'cash')
                    ->count(),

                'today' => [
                    'count'   => Payment::whereDate('paid_at', today())->where('status', 'paid')->count(),
                    'revenue' => Payment::whereDate('paid_at', today())->where('status', 'paid')->sum('amount'),
                ],
                'this_month' => [
                    'count'   => Payment::whereMonth('paid_at', now()->month)->whereYear('paid_at', now()->year)
                        ->where('status', 'paid')->count(),
                    'revenue' => Payment::whereMonth('paid_at', now()->month)->whereYear('paid_at', now()->year)
                        ->where('status', 'paid')->sum('amount'),
                ],
            ];

            return response()->json(['status' => 'success', 'data' => $stats], 200);
        } catch (\Throwable $th) {
            Log::error('PaymentController@stats: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }
}