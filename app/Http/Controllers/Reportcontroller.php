<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReportController extends Controller
{
    public function stats(Request $request)
    {
        try {
            $period = $request->query('period', 'this_month'); // today, this_week, this_month, this_year

            // 1. Determine Date Ranges for Current and Previous periods
            [$currentStart, $currentEnd, $prevStart, $prevEnd] = $this->getDateRanges($period);

            // 2. Statistics & Percentage Changes Calculations
            $stats = $this->calculateStats($currentStart, $currentEnd, $prevStart, $prevEnd);

            // 3. Monthly Sales & Orders Chart Data
            $monthlySales = $this->getMonthlySalesData($period, $currentStart, $currentEnd);

            // 4. Sales by Category Data
            $categoryData = $this->getCategorySalesData($currentStart, $currentEnd);

            // 5. Top Products Performance
            $topProducts = $this->getTopProductsData($currentStart, $currentEnd);

            // 6. Recent Transactions
            $recentTransactions = $this->getRecentTransactionsData();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'stats' => $stats,
                    'monthly_sales' => $monthlySales,
                    'category_data' => $categoryData,
                    'top_products' => $topProducts,
                    'recent_transactions' => $recentTransactions,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Report stats error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch report statistics.',
                'error' => app()->isLocal() ? $e->getMessage() : null
            ], 500);
        }
    }

    private function getDateRanges($period)
    {
        $now = Carbon::now();

        switch ($period) {
            case 'today':
                $currentStart = $now->copy()->startOfDay();
                $currentEnd = $now->copy()->endOfDay();
                $prevStart = $now->copy()->subDay()->startOfDay();
                $prevEnd = $now->copy()->subDay()->endOfDay();
                break;

            case 'this_week':
                $currentStart = $now->copy()->startOfWeek();
                $currentEnd = $now->copy()->endOfWeek();
                $prevStart = $now->copy()->subWeek()->startOfWeek();
                $prevEnd = $now->copy()->subWeek()->endOfWeek();
                break;

            case 'this_year':
                $currentStart = $now->copy()->startOfYear();
                $currentEnd = $now->copy()->endOfYear();
                $prevStart = $now->copy()->subYear()->startOfYear();
                $prevEnd = $now->copy()->subYear()->endOfYear();
                break;

            case 'this_month':
            default:
                $currentStart = $now->copy()->startOfMonth();
                $currentEnd = $now->copy()->endOfMonth();
                $prevStart = $now->copy()->subMonth()->startOfMonth();
                $prevEnd = $now->copy()->subMonth()->endOfMonth();
                break;
        }

        return [$currentStart, $currentEnd, $prevStart, $prevEnd];
    }

    private function calculateStats($currentStart, $currentEnd, $prevStart, $prevEnd)
    {
        // Total Revenue (From paid/delivered orders or payments table)
        $currentRevenue = Order::whereBetween('created_at', [$currentStart, $currentEnd])
            ->where('status', '!=', 'cancelled')
            ->sum('total_amount');

        $prevRevenue = Order::whereBetween('created_at', [$prevStart, $prevEnd])
            ->where('status', '!=', 'cancelled')
            ->sum('total_amount');

        // Total Orders
        $currentOrders = Order::whereBetween('created_at', [$currentStart, $currentEnd])->count();
        $prevOrders = Order::whereBetween('created_at', [$prevStart, $prevEnd])->count();

        // New Customers
        $currentCustomers = User::whereBetween('created_at', [$currentStart, $currentEnd])->count();
        $prevCustomers = User::whereBetween('created_at', [$prevStart, $prevEnd])->count();

        // Average Order Value
        $currentAvgOrder = $currentOrders > 0 ? $currentRevenue / $currentOrders : 0;
        $prevAvgOrder = $prevOrders > 0 ? $prevRevenue / $prevOrders : 0;

        return [
            'total_revenue' => round($currentRevenue, 2),
            'revenue_change_pct' => $this->calculatePercentageChange($currentRevenue, $prevRevenue),
            'revenue_positive' => $currentRevenue >= $prevRevenue,

            'total_orders' => $currentOrders,
            'orders_change_pct' => $this->calculatePercentageChange($currentOrders, $prevOrders),
            'orders_positive' => $currentOrders >= $prevOrders,

            'new_customers' => $currentCustomers,
            'customers_change_pct' => $this->calculatePercentageChange($currentCustomers, $prevCustomers),
            'customers_positive' => $currentCustomers >= $prevCustomers,

            'avg_order_value' => round($currentAvgOrder, 2),
            'avg_change_pct' => $this->calculatePercentageChange($currentAvgOrder, $prevAvgOrder),
            'avg_positive' => $currentAvgOrder >= $prevAvgOrder,
        ];
    }

    private function calculatePercentageChange($current, $previous)
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        return round(abs((($current - $previous) / $previous) * 100), 1);
    }

    private function getMonthlySalesData($period, $start, $end)
    {
        $query = Order::whereBetween('created_at', [$start, $end])
            ->where('status', '!=', 'cancelled');

        if ($period === 'today') {
            // Group by hour for today (Fixed the typo here)
            $sales = $query->select(
                DB::raw("DATE_FORMAT(created_at, '%H:00') as month"),
                DB::raw('SUM(total_amount) as revenue'),
                DB::raw('COUNT(id) as orders')
            )->groupBy('month')->orderBy('month')->get();
        } elseif ($period === 'this_year') {
            // Group by month for this year
            $sales = $query->select(
                DB::raw("DATE_FORMAT(created_at, '%b') as month"),
                DB::raw('SUM(total_amount) as revenue'),
                DB::raw('COUNT(id) as orders')
            )->groupBy('month')->orderBy('created_at')->get();
        } else {
            // Group by day for week/month
            $sales = $query->select(
                DB::raw("DATE_FORMAT(created_at, '%b %d') as month"),
                DB::raw('SUM(total_amount) as revenue'),
                DB::raw('COUNT(id) as orders')
            )->groupBy('month')->orderBy('created_at')->get();
        }

        return $sales->map(function ($item) {
            return [
                'month' => $item->month,
                'revenue' => (float) $item->revenue,
                'orders' => (int) $item->orders,
            ];
        });
    }

    private function getCategorySalesData($start, $end)
    {
        $colors = ['#3B82F6', '#2F6844', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899'];

        $categorySales = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.status', '!=', 'cancelled')
            ->select(
                'categories.name as category_name',
                DB::raw('SUM(order_items.total_price) as total_sales')
            )
            ->groupBy('categories.id', 'categories.name')
            ->get();

        $totalSalesSum = $categorySales->sum('total_sales');

        return $categorySales->map(function ($item, $index) use ($colors, $totalSalesSum) {
            $percentage = $totalSalesSum > 0 ? round(($item->total_sales / $totalSalesSum) * 100, 1) : 0;
            return [
                'name' => $item->category_name,
                'value' => $percentage,
                'color' => $colors[$index % count($colors)],
            ];
        })->toArray();
    }

    private function getTopProductsData($start, $end)
    {
        $topProducts = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.status', '!=', 'cancelled')
            ->select(
                'products.name as product_name',
                'categories.name as category_name',
                DB::raw('SUM(order_items.quantity) as total_sold'),
                DB::raw('SUM(order_items.total_price) as total_revenue')
            )
            ->groupBy('products.id', 'products.name', 'categories.name')
            ->orderByDesc('total_revenue')
            ->limit(5)
            ->get();

        return $topProducts->map(function ($item) {
            return [
                'name' => $item->product_name,
                'category' => $item->category_name ?? 'General',
                'revenue' => '$' . number_format($item->total_revenue, 2),
                'sold' => (int) $item->total_sold,
                'trend' => 'up',
            ];
        })->toArray();
    }

    private function getRecentTransactionsData()
    {
        $orders = Order::with('user')
            ->latest()
            ->limit(6)
            ->get();

        return $orders->map(function ($order) {
            return [
                'id' => '#' . $order->id,
                'customer' => $order->user->name ?? 'Walk-in Customer',
                'amount' => '$' . number_format($order->total_amount, 2),
                'date' => $order->created_at->format('M d, Y'),
                'status' => ucfirst($order->status),
            ];
        })->toArray();
    }
}