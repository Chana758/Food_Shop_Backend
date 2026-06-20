<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            // យើងប្រើ with('category') ដើម្បីទាញយកទិន្នន័យពីតារាង Category មកជាមួយ
            $products = Product::with('category')->paginate(15);
            
            return response()->json([
                'status' => 'success', 
                'data'   => $products
            ], 200);
        } catch (\Throwable $th) {
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
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
                $file = $request->file('image');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->storeAs('products', $filename, 'public');
                $validatedData['image'] = 'products/' . $filename;
            }

            $product = Product::create($validatedData);

            return response()->json(['status' => 'success', 'data' => $product], 201);
        } catch (\Throwable $th) {
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $product = Product::with('category')->find($id);
        if (!$product) {
            return response()->json(['status' => 'error', 'message' => 'Product Not Found!'], 404);
        }
        return response()->json(['status' => 'success', 'data' => $product], 200);
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
                $file = $request->file('image');
                $filename = time() . '_' . $file->getClientOriginalName();
                $file->storeAs("products", $filename, 'public');
                $validatedData['image'] = "products/" . $filename;
            }

            $product->update($validatedData);

            return response()->json([
                'status' => 'success', 
                'message' => 'Product updated successfully!', 
                'data' => $product
            ], 200);
        } catch (\Throwable $th) {
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500); 
        }
    }

    /**
     * Remove the specified resource from storage (Soft Delete).
     */
    public function destroy(Product $product)
    {
        try {
            // ដោយសារយើងប្រើ SoftDelete យើងមិនចាំបាច់លុបរូបភាពចោលទេ (តាមចិត្តចង់)
            // តែបើចង់ឱ្យស្អាត អាចលុបរូបភាពបាន
            $product->delete(); // ផលិតផលនឹងមិនបាត់ពី DB ទេ គ្រាន់តែមាន deleted_at
            
            return response()->json(['status' => 'success', 'message' => 'Product moved to trash'], 200);
        } catch (\Throwable $th) {
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * Restore trashed product.
     */
    public function restore($id)
    {
        $product = Product::withTrashed()->find($id);
        if ($product) {
            $product->restore();
            return response()->json([
                'status' => 'success',
                'message' => 'Product restored'
            ], 200);
        }
        return response()->json([
            'status' => 'error', 
            'message' => 'Product not found'
        ], 404);
    }
    public function forceDelete($id)
    {
        $product = Product::withTrashed()->find($id);
        if ($product) {
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $product->forceDelete();
            return response()->json([
                'status' => 'success',
                'message' => 'Product permanently deleted'
            ], 200);
        }
        return response()->json([
            'status' => 'error', 
            'message' => 'Product not found'
        ], 404);
    }
   
    public function getTrashedProducts()
    {
    // ប្រើ onlyTrashed ដើម្បីទាញយកតែរបស់ដែលបានលុប
    $trashedProducts = Product::onlyTrashed()->with('category')->get();
    
    return response()->json([
        'status' => 'success', 
        'data' => $trashedProducts
    ], 200);
}
                
}