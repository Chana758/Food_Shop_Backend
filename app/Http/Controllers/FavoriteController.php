<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    /**
     * GET /api/favorites
     * Get all favorites for the authenticated user
     */
    public function index()
    {
        try {
            $favorites = Favorite::with('product.category')
                ->where('user_id', auth()->id())
                ->latest()
                ->get();

            return response()->json([
                'status' => 'success',
                'data'   => $favorites,
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/admin/favorites
     * Admin — get all favorites grouped by customer
     */
    public function adminGetAllFavorites()
    {
        try {
            $favorites = Favorite::with(['user', 'product.category'])
                ->latest()
                ->get()
                ->groupBy(function ($item) {
                    return $item->user ? $item->user->name : 'Guest';
                });

            return response()->json([
                'status' => 'success',
                'data'   => $favorites,
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/favorites
     * Add a product to favorites
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        try {
            $favorite = Favorite::firstOrCreate([
                'user_id'    => auth()->id(),
                'product_id' => $validated['product_id'],
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Added to favorites',
                'data'    => $favorite->load('product'),
            ], 201);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/favorites/:product_id
     * User removes own favorite by product_id
     */
    public function destroy($productId)
    {
        try {
            $deleted = Favorite::where('user_id', auth()->id())
                ->where('product_id', $productId)
                ->delete();

            if (!$deleted) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Favorite not found',
                ], 404);
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Removed from favorites',
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/admin/favorites/:id
     * Admin removes any favorite by favorite id
     */
    public function adminDestroy($id)
    {
        try {
            $favorite = Favorite::findOrFail($id);
            $favorite->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Favorite removed by admin',
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/favorites/check/:product_id
     * Check if a product is in the authenticated user's favorites
     */
    public function check($productId)
    {
        $isFav = Favorite::where('user_id', auth()->id())
            ->where('product_id', $productId)
            ->exists();

        return response()->json([
            'status' => 'success',
            'data'   => ['is_favorite' => $isFav],
        ], 200);
    }
}