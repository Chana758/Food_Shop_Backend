<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    // Normalize empty or non-positive discount prices.
    private function normalizeDiscountPrice(Request $request): void
    {
        if ($request->has('discount_price')) {
            $raw = $request->input('discount_price');

            if ($raw === '' || $raw === null || (float) $raw <= 0) {
                $request->merge(['discount_price' => null]);
            }
        }
    }

    // List products with search, category filter, and pagination.
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
            'search' => 'sometimes|nullable|string|max:255',
            'category_slug' => 'sometimes|nullable|string|max:255',
        ]);

        try {
            $perPage = $validated['per_page'] ?? 15;
            $search = $validated['search'] ?? '';
            $categorySlug = $validated['category_slug'] ?? '';

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
                'data' => $products,
            ], 200);

        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to retrieve products.',
            ], 500);
        }
    }

    // Create a product.
    public function store(Request $request)
    {
        $this->normalizeDiscountPrice($request);

        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:products,slug',
            'price' => 'required|numeric|min:0',
            'discount_price' => 'nullable|numeric|min:0|lt:price',
            'discount_expires_at' => 'nullable|date',
            'category_id' => 'required|exists:categories,id',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'description' => 'nullable|string',
            'stock_quantity' => 'required|integer|min:0',
            'prep_time' => 'nullable|integer|min:0',
            'sku' => 'nullable|string|max:255|unique:products,sku',
            'is_active' => 'sometimes|boolean',
            'is_featured' => 'sometimes|boolean',
        ]);

        // Validate the discount against the regular price.
        if (
            isset($validatedData['discount_price']) &&
            (float) $validatedData['discount_price'] >= (float) $validatedData['price']
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Discount price must be lower than the regular price.',
            ], 422);
        }

        try {
            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('products', 'public');

                if ($path === false) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unable to upload product image.',
                    ], 500);
                }

                $validatedData['image'] = $path;
            }

            $product = Product::create($validatedData);

            return response()->json([
                'status' => 'success',
                'data' => $product,
            ], 201);

        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to create product.',
            ], 500);
        }
    }

    // Show a product with its category.
    public function show($id)
    {
        $product = Product::with('category')->find($id);

        if (!$product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $product,
        ], 200);
    }

    // Update product details and image.
    public function update(Request $request, Product $product)
    {
        $this->normalizeDiscountPrice($request);

        $validatedData = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|required|string|max:255|unique:products,slug,' . $product->id,
            'price' => 'sometimes|required|numeric|min:0',
            'discount_price' => 'sometimes|nullable|numeric|min:0',
            'discount_expires_at' => 'sometimes|nullable|date',
            'category_id' => 'sometimes|required|exists:categories,id',
            'image' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'description' => 'sometimes|nullable|string',
            'stock_quantity' => 'sometimes|required|integer|min:0',
            'prep_time' => 'sometimes|nullable|integer|min:0',
            'sku' => 'sometimes|nullable|string|max:255|unique:products,sku,' . $product->id,
            'is_active' => 'sometimes|boolean',
            'is_featured' => 'sometimes|boolean',
        ]);

        // Validate against the final product price.
        $price = $validatedData['price'] ?? $product->price;

        $discountPrice = array_key_exists('discount_price', $validatedData)
            ? $validatedData['discount_price']
            : $product->discount_price;

        if (
            $discountPrice !== null &&
            (float) $discountPrice >= (float) $price
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Discount price must be lower than the regular price.',
            ], 422);
        }

        try {
            $oldImage = $product->image;
            $newImage = null;

            if ($request->hasFile('image')) {
                $newImage = $request->file('image')->store('products', 'public');

                if ($newImage === false) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unable to upload product image.',
                    ], 500);
                }

                $validatedData['image'] = $newImage;
            }

            $product->update($validatedData);

            // Remove the previous image after a successful update.
            if ($newImage && $oldImage && $oldImage !== $newImage) {
                Storage::disk('public')->delete($oldImage);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Product updated successfully',
                'data' => $product->fresh('category'),
            ], 200);

        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to update product.',
            ], 500);
        }
    }

    // Soft delete a product.
    public function destroy(Product $product)
    {
        try {
            $product->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Product moved to trash',
            ], 200);

        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to delete product.',
            ], 500);
        }
    }

    // Restore a trashed product.
    public function restore($id)
    {
        try {
            $product = Product::withTrashed()->find($id);

            if (!$product) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Product not found',
                ], 404);
            }

            $product->restore();

            return response()->json([
                'status' => 'success',
                'message' => 'Product restored',
            ], 200);

        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to restore product.',
            ], 500);
        }
    }

    // Permanently delete a product and its image.
    public function forceDelete($id)
    {
        try {
            $product = Product::withTrashed()->find($id);

            if (!$product) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Product not found',
                ], 404);
            }

            $image = $product->image;

            $product->forceDelete();

            if ($image) {
                Storage::disk('public')->delete($image);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Product permanently deleted',
            ], 200);

        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to permanently delete product.',
            ], 500);
        }
    }

    // List trashed products.
    public function getTrashedProducts()
    {
        try {
            $trashedProducts = Product::onlyTrashed()
                ->with('category')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $trashedProducts,
            ], 200);

        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to retrieve trashed products.',
            ], 500);
        }
    }
}
