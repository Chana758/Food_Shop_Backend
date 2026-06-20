<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->input('q');

        // ការពារកុំឱ្យ Query ទទេ ឬខ្លីពេក
        if (!$query || strlen($query) < 2) {
            return response()->json(['data' => ['products' => [], 'categories' => []]]);
        }

        try {
            // ស្វែងរកផលិតផល
            // ប្រើ latest() ដើម្បីឱ្យផលិតផលថ្មីៗបង្ហាញមុនគេ
            // ប្រើ paginate(12) ជំនួសឱ្យ limit(5) ដើម្បីងាយស្រួលធ្វើ Pagination នៅ Frontend
            $products = Product::where('name', 'like', "%{$query}%")
                        ->orWhere('description', 'like', "%{$query}%")
                        ->latest()
                        ->paginate(12);

            // ស្វែងរក Category
            $categories = Category::where('name', 'like', "%{$query}%")
                        ->latest()
                        ->paginate(12);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'products' => $products, // ឥឡូវនេះវានឹងមានទម្រង់ជា Pagination Object
                    'categories' => $categories
                ]
            ]);
        } catch (\Exception $e) {
            // ការពារមិនឱ្យកម្មវិធី Crash បើមាន Error កើតឡើង
            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong while searching.'
            ], 500);
        }
    }
}