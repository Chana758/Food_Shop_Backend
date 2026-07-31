<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
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
                // ✅ wrap name/description OR inside its own group
                // ដើម្បី​កុំ​ឲ្យ orWhere "leak" ចូល​ប៉ះ category filter ខាងក្រោម
                ->when($search, function ($query) use ($search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%")
                          ->orWhere('description', 'LIKE', "%{$search}%");
                    });
                })
                // filter by category slug directly in DB
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
            $validatedData = $request->validate([
                'name'           => 'required|string|max:255',
                'slug'           => 'required|string|max:255|unique:products',
                'price'          => 'required|numeric',
                'discount_price' => 'nullable|numeric',
                'category_id'    => 'required|exists:categories,id',
                'image'          => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'description'    => 'nullable|string',
                'stock_quantity' => 'required|integer',
                'prep_time'      => 'nullable|integer',
                'sku'            => 'nullable|string|unique:products',
                'is_active'      => 'boolean',
                'is_featured'    => 'boolean',
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
            $validatedData = $request->validate([
                'name'           => 'sometimes|string|max:255',
                'slug'           => 'sometimes|string|max:255|unique:products,slug,' . $product->id,
                'price'          => 'sometimes|numeric',
                'discount_price' => 'nullable|numeric',
                'category_id'    => 'sometimes|exists:categories,id',
                'image'          => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'description'    => 'nullable|string',
                'stock_quantity' => 'sometimes|integer',
                'prep_time'      => 'nullable|integer',
                'sku'            => 'nullable|string',
                'is_active'      => 'boolean',
                'is_featured'    => 'boolean',
            ]);

            if ($request->hasFile('image')) {
                if ($product->image) {
                    Storage::disk('public')->delete($product->image);
                }
                $file     = $request->file('image');
                $filename = time() . '_' . $file->getClientOriginalName();
                $file->storeAs('products', $filename, 'public');
                $validatedData['image'] = 'products/' . $filename;
            }

            $product->update($validatedData);

            return response()->json([
                'status'  => 'success',
                'message' => 'Product updated successfully',
                'data'    => $product
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