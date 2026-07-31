<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderItemController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RiderController;
use App\Http\Controllers\API\Admin\ReportController;
use App\Http\Controllers\API\BackupController;
use App\Http\Controllers\Api\Admin\SettingController;

// ======================================
// PUBLIC
// ======================================
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

Route::get('/categories',    [CategoryController::class, 'index']);
Route::get('/products',      [ProductController::class,  'index']);
Route::get('/products/{id}', [ProductController::class,  'show']);
Route::get('/tables',        [TableController::class,    'index']);

// FIX: was '/contects' (typo — 'a' was missing). Corrected to '/contacts'.
Route::post('/contacts', [ContactController::class, 'store']);

// Public review listing — approved only, filterable by ?product_id=
Route::get('/reviews', [ReviewController::class, 'index']);

// ======================================
// PROTECTED — Authenticated users
// ======================================
Route::middleware(['auth:sanctum', 'check.blocked'])->group(function () {

    Route::get('/user',    fn(Request $r) => $r->user());
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/search',  [SearchController::class, 'index']);

    // ── Orders ────
    Route::get('/orders',                 [OrderController::class, 'myOrders']);
    Route::post('/orders',                [OrderController::class, 'store']);
    Route::get('/orders/{order}',         [OrderController::class, 'show']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);

    // ── Payments ─────
    Route::post('/payments',                          [PaymentController::class, 'store']);
    Route::get('/payments',                           [PaymentController::class, 'myPayments']);
    
    // FIX: Changed GET to POST (has side-effect: updates DB + triggers events) 
    // and added a rate limiter to prevent flooding/spamming external payment gateway APIs (e.g., Bakong).
    Route::post('/payments/{payment}/check-status',    [PaymentController::class, 'checkStatus'])
        ->middleware('throttle:20,1'); // Max 20 requests per minute per user

    Route::post('/payments/{payment}/upload-receipt', [PaymentController::class, 'uploadReceipt']);

    // ── Favorites ──────
    Route::prefix('favorites')->group(function () {
        Route::get('/',                  [FavoriteController::class, 'index']);
        Route::post('/',                 [FavoriteController::class, 'store']);
        Route::delete('/{productId}',    [FavoriteController::class, 'destroy']);
        Route::get('/check/{productId}', [FavoriteController::class, 'check']);
    });

    // ── Reservations (customer) ─────
    Route::prefix('reservations')->group(function () {
        Route::get('/',             [ReservationController::class, 'index']);
        Route::post('/',            [ReservationController::class, 'store']);
        Route::get('/{id}',         [ReservationController::class, 'show']);
        Route::put('/{id}',         [ReservationController::class, 'update']);
        Route::post('/{id}/cancel', [ReservationController::class, 'cancel']);
    });

    // ── Reviews (customer write) ──────
    Route::prefix('reviews')->group(function () {
        Route::post('/',        [ReviewController::class, 'store']);
        Route::put('/{id}',     [ReviewController::class, 'update']);
        Route::delete('/{id}',  [ReviewController::class, 'destroy']);
    });

    // ── Notifications (customer) ────────
    Route::prefix('notifications')->group(function () {
        Route::get('/',             [NotificationController::class, 'index']);
        Route::get('/{id}',         [NotificationController::class, 'show']);
        Route::put('/{id}/read',    [NotificationController::class, 'markAsRead']);
        Route::post('/read-all',    [NotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}',      [NotificationController::class, 'destroy']);
    });

    // ==========================================
    // ADMIN + STAFF — read-only access (GET)
    // ==========================================
    Route::middleware('role:admin,staff')->group(function () {

        Route::get('/admin/dashboard-stats',      [DashboardController::class, 'stats']);
        Route::get('/admin/dashboard-stats/ping', [DashboardController::class, 'ping']);

        // Orders — view only
        Route::prefix('admin/orders')->group(function () {
            Route::get('/stats',         [OrderController::class,     'stats']);
            Route::get('/',              [OrderController::class,     'index']);
            Route::get('/{order}/items', [OrderItemController::class, 'index']);
        });

        // Payments — view only
        Route::prefix('admin/payments')->group(function () {
            Route::get('/stats',     [PaymentController::class, 'stats']);
            Route::get('/',          [PaymentController::class, 'index']);
            Route::get('/{payment}', [PaymentController::class, 'show']);
        });

        // Favorites — view only  
        Route::get('/admin/favorites', [FavoriteController::class, 'adminGetAllFavorites']);

        // Reservations — view only
        Route::prefix('admin/reservations')->group(function () {
            Route::get('/stats', [ReservationController::class, 'stats']); 
            Route::get('/',     [ReservationController::class, 'index']);
            Route::get('/{id}', [ReservationController::class, 'show']);
        });

        // Contacts — view only
        Route::prefix('admin/contacts')->group(function () {
            Route::get('/stats', [ContactController::class, 'stats']);
            Route::get('/',     [ContactController::class, 'index']);
            Route::get('/{id}', [ContactController::class, 'show']);
        });

        // Reviews — view only
        Route::prefix('admin/reviews')->group(function () {
            Route::get('/',     [ReviewController::class, 'index']);
            Route::get('/{id}', [ReviewController::class, 'show']);
        });

        // Reports — view statistics and analytics
        Route::get('/admin/reports/stats', [ReportController::class, 'stats']);

    });

    // =========================
    // ADMIN ONLY — full management
    // =========================
    Route::middleware('role:admin')->group(function () {

        // Categories
        Route::get('/admin/categories/trashed', [CategoryController::class, 'getTrashedCategories']);
        Route::prefix('categories')->group(function () {
            Route::post('/',                    [CategoryController::class, 'store']);
            Route::get('/{category}',           [CategoryController::class, 'show']);
            Route::put('/{category}',           [CategoryController::class, 'update']);
            Route::delete('/{category}',        [CategoryController::class, 'destroy']);
            Route::post('/{id}/restore',        [CategoryController::class, 'restore']);
            Route::delete('/{id}/force-delete', [CategoryController::class, 'forceDelete']);
        });

        // Products
        Route::get('/admin/products/trashed', [ProductController::class, 'getTrashedProducts']);
        Route::prefix('products')->group(function () {
            Route::post('/',                    [ProductController::class, 'store']);
            Route::put('/{product}',            [ProductController::class, 'update']);
            Route::delete('/{product}',         [ProductController::class, 'destroy']);
            Route::post('/{id}/restore',        [ProductController::class, 'restore']);
            Route::delete('/{id}/force-delete', [ProductController::class, 'forceDelete']);
        });

        // Tables
        Route::prefix('tables')->group(function () {
            Route::post('/',          [TableController::class, 'store']);
            Route::get('/{id}',       [TableController::class, 'show']);
            Route::put('/{table}',    [TableController::class, 'update']);
            Route::delete('/{table}', [TableController::class, 'destroy']);
        });

        // Orders — write actions
        Route::prefix('admin/orders')->group(function () {
            Route::put('/{order}',                 [OrderController::class,     'update']);
            Route::delete('/{order}',              [OrderController::class,     'destroy']);
            Route::post('/{order}/items',          [OrderItemController::class, 'store']);
            Route::put('/{order}/assign-rider',    [OrderController::class,     'assignRider']);
            Route::put('/{order}/delivery-status', [OrderController::class,     'updateDeliveryStatus']);
        });

        // Order Items
        Route::prefix('order-items')->group(function () {
            Route::put('/{orderItem}',    [OrderItemController::class, 'update']);
            Route::delete('/{orderItem}', [OrderItemController::class, 'destroy']);
        });

        // Payments — write actions
        Route::prefix('admin/payments')->group(function () {
            Route::put('/{payment}/confirm', [PaymentController::class, 'confirm']);
            Route::put('/{payment}/reject',  [PaymentController::class, 'reject']);
            Route::put('/{payment}/refund',  [PaymentController::class, 'refund']);
            Route::delete('/{payment}',      [PaymentController::class, 'destroy']);
        });

        // Favorites — write
        Route::delete('/admin/favorites/{id}', [FavoriteController::class, 'adminDestroy']);

        // Customers
        Route::prefix('admin/customers')->group(function () {
            Route::get('/',                   [UserController::class, 'getCustomers']);
            Route::post('/',                  [UserController::class, 'storeCustomer']);
            Route::put('/{id}',               [UserController::class, 'updateCustomer']);
            Route::delete('/{id}',            [UserController::class, 'destroyCustomer']);
            Route::put('/{id}/toggle-status', [UserController::class, 'toggleBlockStatus']);
        });

        // Staff
        Route::prefix('admin/staff')->group(function () {
            Route::get('/',        [UserController::class, 'getStaff']);
            Route::post('/',       [UserController::class, 'store']);
            Route::put('/{id}',    [UserController::class, 'updatestaff']);
            Route::delete('/{id}', [UserController::class, 'destroystaff']);
        });

        // Riders
        Route::prefix('admin/riders')->group(function () {
            Route::get('/',           [RiderController::class, 'index']);
            Route::post('/',          [RiderController::class, 'store']);
            Route::put('/{rider}',    [RiderController::class, 'update']);
            Route::delete('/{rider}', [RiderController::class, 'destroy']);
        });

        // Reservations — write actions
        Route::prefix('admin/reservations')->group(function () {
            Route::put('/{id}/confirm',  [ReservationController::class, 'confirm']);
            Route::put('/{id}/reject',   [ReservationController::class, 'reject']);
            Route::put('/{id}/complete', [ReservationController::class, 'complete']);
            Route::delete('/{id}',       [ReservationController::class, 'destroy']);
        });

        // Contacts — write actions
        Route::prefix('admin/contacts')->group(function () {
            Route::post('/{id}/reply', [ContactController::class, 'reply']);
            Route::delete('/{id}',     [ContactController::class, 'destroy']);
        });

        // Reviews — write actions
        Route::prefix('admin/reviews')->group(function () {
            Route::put('/{id}/status', [ReviewController::class, 'updateStatus']);
            Route::delete('/{id}',     [ReviewController::class, 'destroy']);
        });

        // Notifications — admin sends manual notifications
        Route::prefix('admin/notifications')->group(function () {
            Route::post('/', [NotificationController::class, 'store']);
        });

        // Settings — global system configurations management
        Route::prefix('admin/settings')->group(function () {
            Route::get('/', [SettingController::class, 'index']);
            Route::put('/', [SettingController::class, 'update']);
        });

        // Backups — database backup management
        Route::prefix('admin/backups')->group(function () {
            Route::get('/',              [BackupController::class, 'index']);
            Route::post('/',             [BackupController::class, 'store']);
            Route::get('/download',      [BackupController::class, 'download']);
            Route::delete('/{fileName}', [BackupController::class, 'destroy']);
        });

    });

});