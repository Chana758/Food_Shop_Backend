<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    // Low stock threshold
    private const LOW_STOCK_THRESHOLD = 5;

    // Recent orders limit
    private const RECENT_ORDERS_LIMIT = 5;

    // Low stock display limit
    private const LOW_STOCK_LIMIT = 5;

    // Recent delivery orders limit
    private const RECENT_DELIVERY_LIMIT = 5;

    // Return dashboard statistics and recent activity
    public function stats()
    {
        try {
            $today     = Carbon::today();
            $yesterday = Carbon::yesterday();

            // Today's sales
            $todaySales = (float) Payment::where('status', 'paid')
                ->whereDate('paid_at', $today)
                ->sum('amount');

            // Yesterday's sales (for % change)
            $yesterdaySales = (float) Payment::where('status', 'paid')
                ->whereDate('paid_at', $yesterday)
                ->sum('amount');

            // % change vs yesterday
            $salesChangePct = $yesterdaySales > 0
                ? round((($todaySales - $yesterdaySales) / $yesterdaySales) * 100, 1)
                : ($todaySales > 0 ? 100.0 : 0.0);

            // Orders today (all statuses)
            $ordersToday = Order::whereDate('created_at', $today)->count();

            // New customers today (NOTE: needs a 'role' column on users)
            $newCustomersToday = User::where('role', 'customer')
                ->whereDate('created_at', $today)
                ->count();

            // Low stock count
            $lowStockCount = Product::where('stock_quantity', '<=', self::LOW_STOCK_THRESHOLD)
                ->whereNull('deleted_at')
                ->count();

            // Pending payments (non-cash)
            $pendingPaymentsCount = Payment::where('status', 'pending')
                ->where('method', '!=', 'cash')
                ->count();

            // Sales trend (last 7 days)
            $rawTrend = Payment::where('status', 'paid')
                ->whereBetween('paid_at', [
                    Carbon::now()->subDays(6)->startOfDay(),
                    Carbon::now()->endOfDay(),
                ])
                ->select(
                    DB::raw('DATE(paid_at) as day_date'),
                    DB::raw('SUM(amount)   as total_sales')
                )
                ->groupBy('day_date')
                ->orderBy('day_date', 'asc')
                ->get()
                ->keyBy(fn($row) => Carbon::parse($row->day_date)->format('D')); // Mon, Tue...

            // Fill all 7 days (0 if no sales)
            $fullTrend = [];
            for ($i = 6; $i >= 0; $i--) {
                $dayLabel    = Carbon::now()->subDays($i)->format('D'); // Mon
                $dayFull     = Carbon::now()->subDays($i)->format('M j'); // Jun 24
                $fullTrend[] = [
                    'date'     => $dayLabel,
                    'date_full'=> $dayFull,
                    'sales'    => isset($rawTrend[$dayLabel])
                        ? round((float) $rawTrend[$dayLabel]->total_sales, 2)
                        : 0,
                ];
            }

            // Live order queue (kitchen)
            $queueCounts = Order::whereIn('status', ['pending', 'cooking', 'served'])
                ->select('status', DB::raw('COUNT(*) as cnt'))
                ->groupBy('status')
                ->pluck('cnt', 'status')
                ->toArray();

            $liveQueue = [
                'pending' => (int) ($queueCounts['pending'] ?? 0),
                'cooking' => (int) ($queueCounts['cooking'] ?? 0),
                'served'  => (int) ($queueCounts['served']  ?? 0),
            ];

            // Recent orders (all types)
            $recentOrders = Order::with('user:id,name')
                ->latest()
                ->limit(self::RECENT_ORDERS_LIMIT)
                ->get()
                ->map(fn($order) => [
                    'id'         => $order->id,
                    // customer_name first, contact may differ from account name
                    'customer'   => $order->customer_name ?? $order->user?->name ?? 'Guest',
                    'status'     => $order->status,
                    'order_type' => $order->order_type,
                    'total'      => round((float) $order->total_amount, 2),
                    'placed'     => $order->created_at?->format('H:i'),
                ]);

            // Low stock items
            $lowStock = Product::where('stock_quantity', '<=', self::LOW_STOCK_THRESHOLD)
                ->whereNull('deleted_at')
                ->orderBy('stock_quantity', 'asc')
                ->limit(self::LOW_STOCK_LIMIT)
                ->get(['id', 'name', 'stock_quantity'])
                ->map(fn($p) => [
                    'id'     => $p->id,
                    'label'  => $p->name,
                    'weight' => $p->stock_quantity === 0
                        ? 'Out of stock!'
                        : "{$p->stock_quantity} left",
                    'critical' => $p->stock_quantity <= 2, // true = render in red
                ]);

            // Delivery queue
            $deliveryCounts = Order::where('order_type', 'delivery')
                ->whereNotNull('delivery_status')
                ->select('delivery_status', DB::raw('COUNT(*) as cnt'))
                ->groupBy('delivery_status')
                ->pluck('cnt', 'delivery_status')
                ->toArray();

            $deliveryQueue = [
                'unassigned' => (int) ($deliveryCounts['unassigned'] ?? 0),
                'assigned'   => (int) ($deliveryCounts['assigned']   ?? 0),
                'picked_up'  => (int) ($deliveryCounts['picked_up']  ?? 0),
                'on_the_way' => (int) ($deliveryCounts['on_the_way'] ?? 0),
                'delivered'  => (int) ($deliveryCounts['delivered']  ?? 0),
                'failed'     => (int) ($deliveryCounts['failed']     ?? 0),
            ];

            // No rider assigned yet (drives the dashboard banner)
            $unassignedDeliveryCount = $deliveryQueue['unassigned'];

            // Active deliveries
            $recentDeliveryOrders = Order::with(['rider:id,name,phone'])
                ->where('order_type', 'delivery')
                ->whereIn('delivery_status', ['unassigned', 'assigned', 'picked_up', 'on_the_way'])
                ->latest()
                ->limit(self::RECENT_DELIVERY_LIMIT)
                ->get()
                ->map(fn($order) => [
                    'id'              => $order->id,
                    'customer'        => $order->customer_name ?? 'Guest',
                    'address'         => $order->delivery_address,
                    'delivery_status' => $order->delivery_status,
                    'rider'           => $order->rider?->name,
                    'total'           => round((float) $order->total_amount, 2),
                    'placed'          => $order->created_at?->format('H:i'),
                ]);

            // Response
            return response()->json([
                'status' => 'success',

                // Stat cards
                'stats' => [
                    'today_sales'               => $todaySales,
                    'yesterday_sales'           => $yesterdaySales,
                    'sales_change_pct'          => $salesChangePct,
                    'orders_today'              => $ordersToday,
                    'new_customers_today'       => $newCustomersToday,
                    'low_stock_count'           => $lowStockCount,
                    'pending_payments_count'    => $pendingPaymentsCount,
                    'unassigned_delivery_count' => $unassignedDeliveryCount,
                ],

                // Chart
                'sales_trend' => $fullTrend,

                // Live queue chips (kitchen)
                'live_queue' => $liveQueue,

                // Delivery queue chips
                'live_delivery_queue' => $deliveryQueue,

                // Right column
                'recent_orders'          => $recentOrders,
                'recent_delivery_orders' => $recentDeliveryOrders,
                'low_stock'              => $lowStock,

                // Meta
                'generated_at' => now()->toISOString(),
            ], 200);

        } catch (\Throwable $th) {
            \Log::error('DashboardController@stats error: ' . $th->getMessage(), [
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => app()->isLocal()
                    ? $th->getMessage()          // Dev: show detail
                    : 'Dashboard data unavailable.', // Prod: hide detail
            ], 500);
        }
    }

    // GET /api/admin/dashboard-stats/ping (health check)
    public function ping()
    {
        return response()->json([
            'status'   => 'ok',
            'message'  => 'DashboardController is working!',
            'time'     => now()->toISOString(),
            'db_check' => DB::connection()->getPdo() ? 'connected' : 'error',
        ], 200);
    }
}