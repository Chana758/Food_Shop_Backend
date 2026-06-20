<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    // ទាញយក Categories ដោយប្រើ Pagination (១៥ ក្នុងមួយទំព័រ)
    public function index()
    {
        try {
            $categories = Category::paginate(15);
            
            return response()->json([
                'status' => 'success',
                'data'   => $categories
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to fetch categories: ' . $th->getMessage()
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
                'name' => 'required|string|max:255',
                'slug' => 'required|string|max:255|unique:categories',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', 
                'description' => 'nullable|string',
            ]);
            
            if($request->hasFile('image')) {
                $file = $request->file('image');
                $filename = time() . '_' . $file->getClientOriginalName();
                $file->storeAs("categories", $filename, 'public');
                $validatedData['image'] = "categories/" . $filename; 
            } 
            
            $category = Category::create($validatedData);

            return response()->json([
                'status' => 'success',
                'data' => $category
            ], 201);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to create category: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $category = Category::find($id);
        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Category not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $category
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'slug' => 'sometimes|required|string|max:255|unique:categories,slug,' . $category->id,
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'description' => 'nullable|string',
            ]);
            
            if($request->hasFile('image')) {
                if ($category->image) {
                    Storage::disk('public')->delete($category->image);
                }
                $file = $request->file('image');
                $filename = time() . '_' . $file->getClientOriginalName();
                $file->storeAs("categories", $filename, 'public');
                $validatedData['image'] = "categories/" . $filename;
            } 
            
            $category->update($validatedData);

            return response()->json([
                'status' => 'success',
                'message' => 'Category updated successfully',
                'data' => $category
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to update category: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage (Soft Delete).
     */
    public function destroy(Category $category)
    {
        try {
            // ដោយសារប្រើ SoftDelete ផលិតផលមិនបាត់ពី DB ទេ គ្រាន់តែមាន deleted_at
            $category->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Category moved to trash'
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to delete category: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Restore trashed category.
     */
    public function restore($id)
    {
        try {
            $category = Category::withTrashed()->find($id);
            
            if ($category && $category->trashed()) {
                $category->restore();
                return response()->json([
                    'status' => 'success',
                    'message' => 'Category restored successfully'
                ], 200);
            }

            return response()->json(['status' => 'error', 'message' => 'Category not found or not in trash'], 404);
        } catch (\Throwable $th) {
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }
    /**
     * Get all trashed categories (មើលផលិតផលដែលបានលុប).
     */
    public function getTrashedCategories()
    {
        try {
            // ទាញយកតែអ្វីដែលបានលុប (Soft Deleted)
            $trashedCategories = Category::onlyTrashed()->get();
            
            return response()->json([
                'status' => 'success', 
                'data' => $trashedCategories
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error', 
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Force delete a category permanently (លុបចោលទាំងស្រុង).
     */
    public function forceDelete($id)
    {
        try {
            $category = Category::withTrashed()->find($id);
            
            if ($category) {
                // លុបរូបភាពចោលពី Storage
                if ($category->image) {
                    Storage::disk('public')->delete($category->image);
                }
                
                $category->forceDelete();
                
                return response()->json([
                    'status' => 'success',
                    'message' => 'Category permanently deleted'
                ], 200);
            }
            
            return response()->json([
                'status' => 'error', 
                'message' => 'Category not found'
            ], 404);
            
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error', 
                'message' => $th->getMessage()
            ], 500);
        }
    }
}