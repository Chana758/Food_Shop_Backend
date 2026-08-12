<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * ✅ NEW — normalize a "zero" discount_price to null before validation.
     *
     * ROOT CAUSE OF THE BUG THIS PREVENTS: at some point a product got
     * saved with discount_price = 0.00 instead of null (likely a form
     * that submitted "0" rather than leaving the field empty). Because
     * 0.00 is not null, PHP's `discount_price ?? price` fallback in
     * OrderController::store() treated it as "this product really is
     * discounted to $0.00", zeroing out order totals for any order that
     * included it (see Order #40 — total_amount ended up $0.00).
     *
     * The frontend's own hasDiscount() rule (priceUtils.js) already
     * requires discount_price > 0 to count as a real discount, so 0 was
     * never meant to be a valid "on sale" value — normalize it to null
     * at the point of entry so it can never leak into the database again.
     */
    private function normalizeDiscountPrice(Request $request): void
    {
        if ($request->has('discount_price')) {
            $raw = $request->input('discount_price');
            if ($raw === '' || $raw === null || (float) $raw <= 0) {
                $request->merge(['discount_price' => null]);
            }
        }
    }

    /**
     * Display a listing with Search + Category filter + Pagination from Backend
     */
    public function index(Request $request)
    {
        try {
            $perPage      = $request->query('per_page', 15);
            $search       = $request->query('search', '');
            $categorySlug = $request->query('category_slug', '');

            $products = Product::with('category')
                ->when($search, function ($query) use ($search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%")
                          ->orWhere('description', 'LIKE', "%{$search}%");
                    });
                })
                ->when($categorySlug, function ($query) use ($categorySlug) {
                    $query->whereHas('category', function ($q) use ($categorySlug) {
                        $q->where('slug', $categorySlug);
                    });
                })
                ->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data'   => $products
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            // ✅ FIX: normalize discount_price=0 to null BEFORE validation,
            // so it's never stored as a false "active discount".
            $this->normalizeDiscountPrice($request);

            $validatedData = $request->validate([
                'name'                 => 'required|string|max:255',
                'slug'                 => 'required|string|max:255|unique:products',
                'price'                => 'required|numeric|min:0',
                'discount_price'       => 'nullable|numeric|min:0|lt:price',
                'discount_expires_at'  => 'nullable|date',
                'category_id'          => 'required|exists:categories,id',
                'image'                => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'description'          => 'nullable|string',
                'stock_quantity'       => 'required|integer|min:0',
                'prep_time'            => 'nullable|integer|min:0',
                'sku'                  => 'nullable|string|unique:products',
                'is_active'            => 'boolean',
                'is_featured'          => 'boolean',
            ]);

            if ($request->hasFile('image')) {
                $file     = $request->file('image');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->storeAs('products', $filename, 'public');
                $validatedData['image'] = 'products/' . $filename;
            }

            $product = Product::create($validatedData);

            return response()->json([
                'status' => 'success',
                'data'   => $product
            ], 201);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $product = Product::with('category')->find($id);

        if (!$product) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Product not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $product
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product)
    {
        try {
            // ✅ FIX: normalize discount_price=0 to null BEFORE validation —
            // same reasoning as store(). This also covers the case where
            // an admin edits an existing product and the form re-submits
            // a stale "0" instead of leaving discount_price blank.
            $this->normalizeDiscountPrice($request);

            $validatedData = $request->validate([
                'name'                 => 'sometimes|string|max:255',
                'slug'                 => 'sometimes|string|max:255|unique:products,slug,' . $product->id,
                'price'                => 'sometimes|numeric|min:0',
                'discount_price'       => 'nullable|numeric|min:0|lt:price',
                'discount_expires_at'  => 'nullable|date',
                'category_id'          => 'sometimes|exists:categories,id',
                'image'                => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'description'          => 'nullable|string',
                'stock_quantity'       => 'sometimes|integer|min:0',
                'prep_time'            => 'nullable|integer|min:0',
                'sku'                  => 'nullable|string',
                'is_active'            => 'boolean',
                'is_featured'          => 'boolean',
            ]);

            // ✅ Allow explicitly clearing the discount / expiry (empty string from form -> null)
            // (normalizeDiscountPrice() above already handles discount_price === '' / 0,
            // but this stays as a defensive fallback in case validated data
            // still carries an empty string through some other path.)
            if ($request->has('discount_price') && $request->input('discount_price') === '') {
                $validatedData['discount_price'] = null;
            }
            if ($request->has('discount_expires_at') && $request->input('discount_expires_at') === '') {
                $validatedData['discount_expires_at'] = null;
            }

            if ($request->hasFile('image')) {
                if ($product->image) {
                    Storage::disk('public')->delete($product->image);
                }
                $file     = $request->file('image');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->storeAs('products', $filename, 'public');
                $validatedData['image'] = 'products/' . $filename;
            }

            $product->update($validatedData);

            return response()->json([
                'status'  => 'success',
                'message' => 'Product updated successfully',
                'data'    => $product->fresh('category')
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Soft Delete
     */
    public function destroy(Product $product)
    {
        try {
            $product->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Product moved to trash'
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Restore trashed product.
     */
    public function restore($id)
    {
        try {
            $product = Product::withTrashed()->find($id);

            if (!$product) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Product not found'
                ], 404);
            }

            $product->restore();

            return response()->json([
                'status'  => 'success',
                'message' => 'Product restored'
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Force Delete permanently.
     */
    public function forceDelete($id)
    {
        try {
            $product = Product::withTrashed()->find($id);

            if (!$product) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Product not found'
                ], 404);
            }

            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }

            $product->forceDelete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Product permanently deleted'
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get all trashed products.
     */
    public function getTrashedProducts()
    {
        try {
            $trashedProducts = Product::onlyTrashed()->with('category')->get();

            return response()->json([
                'status' => 'success',
                'data'   => $trashedProducts
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage()
            ], 500);
        }
    }
}