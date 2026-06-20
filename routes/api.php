<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;       
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\CategoryController;  
use App\Http\Controllers\ProductController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TableController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// --- 1. Public Routes (អ្នកណាក៏អាចមើលបាន) ---
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/tables', [TableController::class, 'index']);
Route::post('/tables', [TableController::class, 'store']);
Route::put('/tables/{table}', [TableController::class, 'update']);
Route::delete('/tables/{table}', [TableController::class, 'destroy']);
// --- 2. Protected Routes (ទាល់តែ Login ទើបបាន) ---
Route::middleware(['auth:sanctum', 'check.blocked'])->group(function () {
    
    // User Basic Info
    Route::get('/user', function (Request $request) { return $request->user(); });
    Route::post('/logout', [AuthController::class, 'logout']);

    // បងអាចដាក់ Route សម្រាប់អតិថិជន (Add to cart, Checkout...) នៅទីនេះ 
    // ដែល Middleware 'check.blocked' នឹងការពារមិនឱ្យ User ដែលជាប់ Block ប្រើបាន
    
    // --- 3. Admin ONLY Routes (ទាល់តែ Admin ទើបចូលបាន) ---
    // បញ្ជាក់៖ គ្រប់ Route ក្នុងក្រុមនេះ នឹងត្រូវឆ្លងកាត់ទាំង 'role:admin' និង 'check.blocked'
    Route::middleware('role:admin')->group(function () {
        
        // Admin Dashboard &   
        Route::get('/admin/dashboard-stats', [AdminController::class, 'stats']);
        Route::get('/admin/orders', [AdminController::class, 'getAllOrders']);
        Route::put('/admin/orders/{id}', [AdminController::class, 'updateStatus']);

        // Customer Management 
        Route::get('/admin/customers', [UserController::class, 'getCustomers']);
        Route::post('/admin/customers', [UserController::class, 'storeCustomer']);
        Route::put('/admin/customers/{id}', [UserController::class, 'updateCustomer']);
        Route::delete('/admin/customers/{id}', [UserController::class, 'destroyCustomer']);
        Route::put('/admin/customers/{id}/toggle-status', [UserController::class, 'toggleBlockStatus']);

        // Staff Management
        Route::get('/admin/staff', [UserController::class, 'getStaff']);   
        Route::post('/admin/staff', [UserController::class, 'store']);      
        Route::put('/admin/staff/{id}', [UserController::class, 'updatestaff']);
        Route::delete('/admin/staff/{id}', [UserController::class, 'destroystaff']);     
        
        // Category Management
        Route::prefix('categories')->group(function () {  
            Route::post('/', [CategoryController::class, 'store']);
            Route::get('/{category}', [CategoryController::class, 'show']);
            Route::put('/{category}', [CategoryController::class, 'update']);
            Route::delete('/{category}', [CategoryController::class, 'destroy']);
            Route::post('/{id}/restore', [CategoryController::class, 'restore']);
            Route::delete('/{id}/force-delete', [CategoryController::class, 'forceDelete']);
        });
        Route::get('/admin/categories/trashed', [CategoryController::class, 'getTrashedCategories']);

        // Product Management
        Route::prefix('products')->group(function () {
            Route::post('/', [ProductController::class, 'store']);
            Route::get('/{product:slug}', [ProductController::class, 'show']);
            Route::put('/{product}', [ProductController::class, 'update']);
            Route::delete('/{product}', [ProductController::class, 'destroy']);
            Route::post('/{id}/restore', [ProductController::class, 'restore']);
            Route::delete('/{id}/force-delete', [ProductController::class, 'forceDelete']);
        });
        Route::get('/admin/products/trashed', [ProductController::class, 'getTrashedProducts']);

        // Table
        
        // Global Search
        Route::get('/search', [SearchController::class, 'index']);
    });
});