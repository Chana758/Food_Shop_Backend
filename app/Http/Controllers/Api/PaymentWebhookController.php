<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    /** Receive a payment callback, then verify and process it by transaction_id. */
    public function handle(Request $request)
    {
        $transactionId = $request->input('transaction_id');

        // Reject callbacks without a transaction id
        if (!$transactionId) {
            return response()->json([
                'message' => 'Invalid Request',
            ], 400);
        }

        $success = $this->paymentService->verifyAndProcessPayment($transactionId);

        if ($success) {
            return response()->json([
                'message' => 'Payment processed successfully',
            ], 200);
        }

        return response()->json([
            'message' => 'Payment verification failed',
        ], 400);
    }
}