<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $query = trim($request->input('q', ''));

        // Skip short queries
        if (!$query || strlen($query) < 2) {
            return response()->json([
                'status' => 'success',
                'data'   => ['products' => [], 'categories' => []],
            ]);
        }

        try {
            // Wrap in closure so orWhere doesn't leak outside the scope
            $products = Product::where(function ($q) use ($query) {
                    $q->where('name',        'like', "%{$query}%")
                      ->orWhere('description', 'like', "%{$query}%");
                })
                ->latest()
                ->paginate(12);

            $categories = Category::where('name', 'like', "%{$query}%")
                ->latest()
                ->paginate(12);

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'products'   => $products,   // paginated object
                    'categories' => $categories, // paginated object
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Something went wrong while searching.',
            ], 500);
        }
    }
}