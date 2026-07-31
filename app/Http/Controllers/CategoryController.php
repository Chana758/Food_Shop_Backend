<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    /**
     * Display a listing with Search + Pagination from Backend
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->query('per_page', 15);
            $search  = $request->query('search', '');

            $categories = Category::when($search, function ($query) use ($search) {
                    $query->where('name', 'LIKE', "%{$search}%")
                          ->orWhere('description', 'LIKE', "%{$search}%");
                })
                ->paginate($perPage);

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
                'name'        => 'required|string|max:255',
                'slug'        => 'required|string|max:255|unique:categories',
                'image'       => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'description' => 'nullable|string',
            ]);

            if ($request->hasFile('image')) {
                $file     = $request->file('image');
                $filename = time() . '_' . $file->getClientOriginalName();
                $file->storeAs('categories', $filename, 'public');
                $validatedData['image'] = 'categories/' . $filename;
            }

            $category = Category::create($validatedData);

            return response()->json([
                'status' => 'success',
                'data'   => $category
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
                'status'  => 'error',
                'message' => 'Category not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $category
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category)
    {
        try {
            $validatedData = $request->validate([
                'name'        => 'sometimes|required|string|max:255',
                'slug'        => 'sometimes|required|string|max:255|unique:categories,slug,' . $category->id,
                'image'       => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'description' => 'nullable|string',
            ]);

            if ($request->hasFile('image')) {
                if ($category->image) {
                    Storage::disk('public')->delete($category->image);
                }
                $file     = $request->file('image');
                $filename = time() . '_' . $file->getClientOriginalName();
                $file->storeAs('categories', $filename, 'public');
                $validatedData['image'] = 'categories/' . $filename;
            }

            $category->update($validatedData);

            return response()->json([
                'status'  => 'success',
                'message' => 'Category updated successfully',
                'data'    => $category
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to update category: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Soft Delete
     */
    public function destroy(Category $category)
    {
        try {
            $category->delete();

            return response()->json([
                'status'  => 'success',
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

            if (!$category || !$category->trashed()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Category not found or not in trash'
                ], 404);
            }

            $category->restore();

            return response()->json([
                'status'  => 'success',
                'message' => 'Category restored successfully'
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get all trashed categories.
     */
    public function getTrashedCategories()
    {
        try {
            $trashedCategories = Category::onlyTrashed()->get();

            return response()->json([
                'status' => 'success',
                'data'   => $trashedCategories
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
            $category = Category::withTrashed()->find($id);

            if (!$category) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Category not found'
                ], 404);
            }

            if ($category->image) {
                Storage::disk('public')->delete($category->image);
            }

            $category->forceDelete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Category permanently deleted'
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage()
            ], 500);
        }
    }
}