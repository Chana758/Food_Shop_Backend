<?php

namespace App\Http\Controllers;

class PricingController extends Controller
{
    /**
     * GET /api/pricing
     * Public endpoint — Cart.jsx needs this before login too.
     */
    public function index()
    {
        return response()->json([
            'status' => 'success',
            'data'   => [
                'delivery_fee'            => (float) config('pricing.delivery_fee'),
                'free_delivery_threshold' => (float) config('pricing.free_delivery_threshold'),
            ],
        ], 200);
    }
}