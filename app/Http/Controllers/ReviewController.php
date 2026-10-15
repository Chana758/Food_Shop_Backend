<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ReviewController extends Controller
{
    /** Order statuses that count as a verified purchase (match your Order model). */
    private const PURCHASE_VERIFIED_STATUSES = ['completed', 'delivered', 'served'];

    /** List approved reviews (public). Optional ?product_id= filter. */
    public function index(Request $request)
    {
        try {
            $reviews = Review::with('user:id,name')
                ->where('status', Review::STATUS_APPROVED)
                ->when($request->product_id, fn($q) => $q->where('product_id', $request->product_id))
                ->latest()
                ->paginate($request->per_page ?? 15);

            return response()->json(['status' => 'success', 'data' => $reviews], 200);

        } catch (\Throwable $th) {
            Log::error('ReviewController@index: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /** List my reviews, any status. */
    public function myReviews(Request $request)
    {
        try {
            $reviews = Review::with('product:id,name')
                ->where('user_id', Auth::id())
                ->latest()
                ->paginate($request->per_page ?? 15);

            return response()->json(['status' => 'success', 'data' => $reviews], 200);

        } catch (\Throwable $th) {
            Log::error('ReviewController@myReviews: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /** Admin: show one review with its user, product and order. */
    public function show($id)
    {
        try {
            $review = Review::with(['user:id,name', 'product:id,name', 'order:id,status'])
                ->findOrFail($id);

            return response()->json(['status' => 'success', 'data' => $review], 200);

        } catch (\Throwable $th) {
            Log::error('ReviewController@show: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /** Submit a review (if order_id is sent, it must be my completed order containing the product). */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'order_id'   => 'nullable|exists:orders,id',
            'rating'     => 'required|integer|min:1|max:5',
            'comment'    => 'nullable|string',
        ]);

        if (!empty($validated['order_id'])) {
            $order = Order::with('items')->find($validated['order_id']);

            if (!$order || $order->user_id !== Auth::id()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'This order does not belong to you.',
                ], 403);
            }

            if (!in_array($order->status, self::PURCHASE_VERIFIED_STATUSES, true)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'You can only review products from a completed order.',
                ], 422);
            }

            $orderContainsProduct = $order->items
                ->contains(fn($item) => $item->product_id == $validated['product_id']);

            if (!$orderContainsProduct) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'This product was not part of the specified order.',
                ], 422);
            }
        }

        // Return a clean 409 instead of a SQL unique-key 500
        $exists = Review::where('user_id', Auth::id())
            ->where('product_id', $validated['product_id'])
            ->where('order_id', $validated['order_id'] ?? null)
            ->exists();

        if ($exists) {
            return response()->json([
                'status'  => 'error',
                'message' => 'You have already reviewed this product for this order.',
            ], 409);
        }

        try {
            $review = Review::create([
                'user_id'    => Auth::id(),
                'product_id' => $validated['product_id'],
                'order_id'   => $validated['order_id'] ?? null,
                'rating'     => $validated['rating'],
                'comment'    => $validated['comment'] ?? null,
                'status'     => Review::STATUS_APPROVED, // use STATUS_PENDING to moderate first
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Review submitted successfully.',
                'data'    => $review->load(['user:id,name', 'product:id,name']),
            ], 201);

        } catch (\Throwable $th) {
            Log::error('ReviewController@store: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /** Owner edits their review's rating and comment. */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'rating'  => 'sometimes|integer|min:1|max:5',
            'comment' => 'nullable|string',
        ]);

        try {
            $review = Review::findOrFail($id);

            if ($review->user_id !== Auth::id()) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
            }

            $review->update($validated);

            return response()->json([
                'status'  => 'success',
                'message' => 'Review updated successfully.',
                'data'    => $review->fresh(['user:id,name', 'product:id,name']),
            ], 200);

        } catch (\Throwable $th) {
            Log::error('ReviewController@update: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /** Admin: approve or reject a review. */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:' . implode(',', Review::VALID_STATUSES),
        ]);

        try {
            $review = Review::findOrFail($id);
            $review->update(['status' => $validated['status']]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Review status updated.',
                'data'    => $review->fresh(['user:id,name', 'product:id,name']),
            ], 200);

        } catch (\Throwable $th) {
            Log::error('ReviewController@updateStatus: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /** Delete a review (owner or admin). */
    public function destroy($id)
    {
        try {
            $review = Review::findOrFail($id);

            if ($review->user_id !== Auth::id() && Auth::user()->role !== 'admin') {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
            }

            $review->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Review deleted.',
            ], 200);

        } catch (\Throwable $th) {
            Log::error('ReviewController@destroy: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }
}