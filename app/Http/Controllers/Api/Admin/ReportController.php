<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ReportController extends Controller
{
    /**
     *  NEW — the order_items line-total column has been referenced under
     * two different names across this codebase (OrderController::store()
     * inserts `subtotal`, but earlier report code assumed `total_price`).
     * Resolving it once here — via Schema::hasColumn() — means this file
     * keeps working regardless of which one actually exists in the DB,
     * instead of silently returning zero rows from a bad column name.
     */
    private function lineTotalColumn(): string
    {
        return Schema::hasColumn('order_items', 'subtotal') ? 'subtotal' : 'total_price';
    }

    public function stats(Request $request)
    {
        try {
            $period = $request->query('period', 'this_month');

            [$currentStart, $currentEnd, $prevStart, $prevEnd] = $this->getDateRanges($period);

            $stats               = $this->calculateStats($currentStart, $currentEnd, $prevStart, $prevEnd);
            $monthlySales        = $this->getMonthlySalesData($period, $currentStart, $currentEnd);
            $categoryData        = $this->getCategorySalesData($currentStart, $currentEnd);
            $topProducts         = $this->getTopProductsData($currentStart, $currentEnd);
            $recentTransactions  = $this->getRecentTransactionsData();

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'stats'                => $stats,
                    'monthly_sales'        => $monthlySales,
                    'category_data'        => $categoryData,
                    'top_products'         => $topProducts,
                    'recent_transactions'  => $recentTransactions,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Report stats error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to fetch report statistics.',
                'error'   => app()->isLocal() ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function getDateRanges($period)
    {
        $now = Carbon::now();

        switch ($period) {
            case 'today':
                $currentStart = $now->copy()->startOfDay();
                $currentEnd   = $now->copy()->endOfDay();
                $prevStart    = $now->copy()->subDay()->startOfDay();
                $prevEnd      = $now->copy()->subDay()->endOfDay();
                break;

            case 'this_week':
                $currentStart = $now->copy()->startOfWeek();
                $currentEnd   = $now->copy()->endOfWeek();
                $prevStart    = $now->copy()->subWeek()->startOfWeek();
                $prevEnd      = $now->copy()->subWeek()->endOfWeek();
                break;

            case 'this_year':
                $currentStart = $now->copy()->startOfYear();
                $currentEnd   = $now->copy()->endOfYear();
                $prevStart    = $now->copy()->subYear()->startOfYear();
                $prevEnd      = $now->copy()->subYear()->endOfYear();
                break;

            case 'this_month':
            default:
                $currentStart = $now->copy()->startOfMonth();
                $currentEnd   = $now->copy()->endOfMonth();
                $prevStart    = $now->copy()->subMonth()->startOfMonth();
                $prevEnd      = $now->copy()->subMonth()->endOfMonth();
                break;
        }

        return [$currentStart, $currentEnd, $prevStart, $prevEnd];
    }

    private function calculateStats($currentStart, $currentEnd, $prevStart, $prevEnd)
    {
        $currentRevenue = Order::whereBetween('created_at', [$currentStart, $currentEnd])
            ->where('status', '!=', 'cancelled')
            ->sum('total_amount');

        $prevRevenue = Order::whereBetween('created_at', [$prevStart, $prevEnd])
            ->where('status', '!=', 'cancelled')
            ->sum('total_amount');

        $currentOrders = Order::whereBetween('created_at', [$currentStart, $currentEnd])->count();
        $prevOrders    = Order::whereBetween('created_at', [$prevStart, $prevEnd])->count();

        $currentCustomers = User::where('role', 'customer')
            ->whereBetween('created_at', [$currentStart, $currentEnd])->count();
        $prevCustomers = User::where('role', 'customer')
            ->whereBetween('created_at', [$prevStart, $prevEnd])->count();

        $currentAvgOrder = $currentOrders > 0 ? $currentRevenue / $currentOrders : 0;
        $prevAvgOrder    = $prevOrders > 0 ? $prevRevenue / $prevOrders : 0;

        return [
            'total_revenue'       => round($currentRevenue, 2),
            'revenue_change_pct'  => $this->calculatePercentageChange($currentRevenue, $prevRevenue),
            'revenue_positive'    => $currentRevenue >= $prevRevenue,

            'total_orders'        => $currentOrders,
            'orders_change_pct'   => $this->calculatePercentageChange($currentOrders, $prevOrders),
            'orders_positive'     => $currentOrders >= $prevOrders,

            'new_customers'       => $currentCustomers,
            'customers_change_pct'=> $this->calculatePercentageChange($currentCustomers, $prevCustomers),
            'customers_positive'  => $currentCustomers >= $prevCustomers,

            'avg_order_value'     => round($currentAvgOrder, 2),
            'avg_change_pct'      => $this->calculatePercentageChange($currentAvgOrder, $prevAvgOrder),
            'avg_positive'        => $currentAvgOrder >= $prevAvgOrder,
        ];
    }

    private function calculatePercentageChange($current, $previous)
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        return round(abs((($current - $previous) / $previous) * 100), 1);
    }

    /**
     * ✅ FIXED FOR POSTGRESQL
     * Was: DATE_FORMAT(created_at, '%H:00')  ← MySQL-only, throws
     *      "function date_format does not exist" on Postgres, which
     *      caused every single Report load to 500 silently.
     * Now: TO_CHAR(created_at, 'HH24:00')    ← PostgreSQL equivalent
     */
    private function getMonthlySalesData($period, $start, $end)
    {
        $query = Order::whereBetween('created_at', [$start, $end])
            ->where('status', '!=', 'cancelled');

        if ($period === 'today') {
            $sales = $query->select(
                DB::raw("TO_CHAR(created_at, 'HH24:00') as month"),
                DB::raw('SUM(total_amount) as revenue'),
                DB::raw('COUNT(id) as orders')
            )->groupBy('month')->orderBy('month')->get();

        } elseif ($period === 'this_year') {
            $sales = $query->select(
                DB::raw("TO_CHAR(created_at, 'Mon') as month"),
                DB::raw("EXTRACT(MONTH FROM created_at) as month_num"),
                DB::raw('SUM(total_amount) as revenue'),
                DB::raw('COUNT(id) as orders')
            )
            ->groupBy('month', 'month_num')
            ->orderBy('month_num')
            ->get();

        } else {
            $sales = $query->select(
                DB::raw("TO_CHAR(created_at, 'Mon DD') as month"),
                DB::raw("MIN(created_at) as sort_date"),
                DB::raw('SUM(total_amount) as revenue'),
                DB::raw('COUNT(id) as orders')
            )
            ->groupBy('month')
            ->orderBy('sort_date')
            ->get();
        }

        return $sales->map(fn($item) => [
            'month'   => $item->month,
            'revenue' => (float) $item->revenue,
            'orders'  => (int) $item->orders,
        ])->values();
    }

    private function getCategorySalesData($start, $end)
    {
        $col    = $this->lineTotalColumn(); // 'subtotal' or 'total_price'
        $colors = ['#3B82F6', '#2F6844', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899'];

        $categorySales = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.status', '!=', 'cancelled')
            ->select(
                'categories.name as category_name',
                DB::raw("SUM(order_items.{$col}) as total_sales")
            )
            ->groupBy('categories.id', 'categories.name')
            ->get();

        $totalSalesSum = $categorySales->sum('total_sales');

        return $categorySales->map(function ($item, $index) use ($colors, $totalSalesSum) {
            $percentage = $totalSalesSum > 0 ? round(($item->total_sales / $totalSalesSum) * 100, 1) : 0;
            return [
                'name'  => $item->category_name,
                'value' => $percentage,
                'color' => $colors[$index % count($colors)],
            ];
        })->values()->toArray();
    }

    private function getTopProductsData($start, $end)
    {
        $col = $this->lineTotalColumn();

        $topProducts = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.status', '!=', 'cancelled')
            ->select(
                'products.name as product_name',
                'categories.name as category_name',
                DB::raw('SUM(order_items.quantity) as total_sold'),
                DB::raw("SUM(order_items.{$col}) as total_revenue")
            )
            ->groupBy('products.id', 'products.name', 'categories.name')
            ->orderByDesc('total_revenue')
            ->limit(5)
            ->get();

        return $topProducts->map(fn($item) => [
            'name'     => $item->product_name,
            'category' => $item->category_name ?? 'General',
            'revenue'  => '$' . number_format($item->total_revenue, 2),
            'sold'     => (int) $item->total_sold,
            'trend'    => 'up',
        ])->values()->toArray();
    }

    private function getRecentTransactionsData()
    {
        $orders = Order::with('user')->latest()->limit(6)->get();

        return $orders->map(fn($order) => [
            'id'       => '#' . $order->id,
            'customer' => $order->customer_name ?? $order->user?->name ?? 'Walk-in Customer',
            'amount'   => '$' . number_format($order->total_amount, 2),
            'date'     => $order->created_at->format('M d, Y'),
            'status'   => ucfirst($order->status),
        ])->values()->toArray();
    }
}