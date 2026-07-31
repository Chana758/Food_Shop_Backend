<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ReviewController extends Controller
{
    /**
     * ASSUMPTION: adjust these to match your actual Order status values
     * (check App\Models\Order for its real STATUS_* constants / column
     * values — e.g. it may be 'delivered', 'served', 'completed', etc.)
     */
    private const PURCHASE_VERIFIED_STATUSES = ['completed', 'delivered', 'served'];

    /**
     * GET /api/reviews  (public)
     * List approved reviews, optionally filtered by product_id.
     */
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

    /**
     * GET /api/reviews/my  ✅ NEW
     * Authenticated customer: their own review history, any status,
     * across all products.
     *
     * FIX: this route and method did not exist at all, even though the
     * frontend's reviewService.getMyReviews() called /reviews/my. It was
     * silently 404ing (or matching nothing, since /reviews/{id} is only
     * defined for PUT/DELETE, not GET).
     */
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

    /**
     * GET /api/admin/reviews/{id}
     * Admin/staff view a single review with full relations.
     */
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

    /**
     * POST /api/reviews
     * Authenticated customer submits a review.
     *
     * FIX (new): previously `order_id` was only checked with
     * `exists:orders,id` — nothing stopped a user from passing someone
     * else's order id, an order that didn't contain this product, or an
     * order that was never actually completed, to fake a "verified"
     * review. This now enforces:
     *   1. the order belongs to the authenticated user
     *   2. the order actually contains this product
     *   3. the order is in a completed/delivered state
     * If order_id is omitted, the review is simply not purchase-verified
     * (still allowed, same as before — remove that branch if you want to
     * require order_id on every review).
     */
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

        // Explicit duplicate check gives a readable 409 instead of a
        // raw SQL unique-constraint violation bubbling up as a 500.
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
                'status'     => Review::STATUS_APPROVED, // change to STATUS_PENDING for moderation
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

    /**
     * PUT /api/reviews/{id}
     * Owner edits their own review (rating + comment only).
     */
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

    /**
     * PUT /api/admin/reviews/{id}/status
     * Admin approves or rejects a review that was submitted as 'pending'.
     */
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

    /**
     * DELETE /api/reviews/{id}
     * Owner or admin can delete a review.
     */
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