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

    public function confirm(Payment $payment)
    {
        if ($payment->status !== 'pending') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Only pending payments can be confirmed. Current status: ' . $payment->status,
            ], 422);
        }

        try {
            DB::transaction(function () use ($payment) {
                $locked = Payment::lockForUpdate()->findOrFail($payment->id);

                if ($locked->status === 'paid') {
                    return;
                }

                $locked->update(['status' => 'paid', 'paid_at' => now()]);
                $locked->order->update(['status' => 'paid']);
            });

            DB::afterCommit(function () use ($payment) {
                event(new OrderPaid($payment->order->fresh()));
            });

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
    //  CUSTOMER ENDPOINTS
    // ──────────────────────────────────────────────

    /**
     * POST /api/payments
     * FIX: ព័ទ្ធជុំវិញ file upload + Payment::create() ជាមួយ DB::transaction
     * ដើម្បីកុំឲ្យសល់ orphaned file ក្នុង storage បើ create() fail ក្រោយ upload success
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id'        => 'required|exists:orders,id',
            'method'          => 'required|in:cash,khqr,card',
            'transaction_ref' => 'nullable|string|unique:payments,transaction_ref',
            'receipt_image'   => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
        ]);

        try {
            $order = Order::findOrFail($validated['order_id']);

            $existingPending = Payment::where('order_id', $order->id)
                ->whereIn('status', ['pending', 'paid'])
                ->exists();

            if ($existingPending) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'A pending or paid payment already exists for this order.',
                ], 422);
            }

            $receiptPath = null;

            $payment = DB::transaction(function () use ($request, $validated, $order, &$receiptPath) {
                if ($request->hasFile('receipt_image')) {
                    $receiptPath = $request->file('receipt_image')->store('payments/receipts', 'public');
                }

                return Payment::create([
                    'order_id'        => $order->id,
                    'user_id'         => auth()->id(),
                    'amount'          => $order->total_amount,
                    'method'          => $validated['method'],
                    'status'          => 'pending',
                    'transaction_ref' => $validated['transaction_ref'] ?? null,
                    'receipt_image'   => $receiptPath,
                    'paid_at'         => null,
                ]);
            });

            return response()->json([
                'status' => 'success',
                'data'   => $payment->load('order', 'user'),
            ], 201);
        } catch (\Throwable $th) {
            // FIX: ប្រសិនបើ Payment::create() fail ក្រោយ file ត្រូវបាន upload រួច
            // លុប file ចោលកុំឲ្យសល់ orphan ក្នុង storage
            if ($receiptPath) {
                Storage::disk('public')->delete($receiptPath);
            }
            Log::error('PaymentController@store: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * POST /api/payments/{payment}/check-status
     * FIX: config('services.bakong.token') — key ដើមខុស ('api_token' → token តែងតែ null)
     * FIX: role check រួមបញ្ចូល 'staff' ផងដែរ ស្របតាម middleware role:admin,staff កន្លែងផ្សេង
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
            // ✅ FIX: 'services.bakong.token' — មិនមែន 'services.bakong.api_token' ទេ
            $bakong = new BakongKHQR(config('services.bakong.token'));
            $result = $bakong->checkTransactionByMD5($payment->transaction_ref);

            $responseCode = $result->responseCode ?? ($result->status->code ?? null);

            if ($responseCode === 0) {
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

            return response()->json(['status' => 'success', 'paid' => false]);
        } catch (\Throwable $th) {
            Log::error('Bakong checkStatus failed for payment #' . $payment->id . ': ' . $th->getMessage());
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

    // ──────────────────────────────────────────────
    //  ADMIN STATS
    // ──────────────────────────────────────────────

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