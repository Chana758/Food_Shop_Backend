<?php

namespace App\Console\Commands;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\Payment;
use App\Services\InventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireStalePendingOrders extends Command
{
    
    protected $signature = 'orders:expire-pending {--minutes=3}';

    protected $description = 'Cancel KHQR orders whose payment never completed (e.g. customer closed the tab) and restore stock.';

    public function __construct(private readonly InventoryService $inventory)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));

        $staleOrderIds = Payment::where('status', 'pending')
            ->where('method', '!=', 'cash')
            ->whereHas('order', fn ($q) => $q->where('status', 'pending')
                ->where('created_at', '<', $cutoff))
            ->pluck('order_id');

        if ($staleOrderIds->isEmpty()) {
            $this->info('គ្មាន stale pending order ទេ។');
            return self::SUCCESS;
        }

        foreach (Order::whereIn('id', $staleOrderIds)->get() as $order) {
            try {
                DB::transaction(function () use ($order) {
                    $this->inventory->restoreStock($order);
                    $order->update(['status' => 'cancelled']);

                    Payment::where('order_id', $order->id)
                        ->where('status', 'pending')
                        ->update(['status' => 'rejected']);
                });

                DB::afterCommit(function () use ($order) {
                    try {
                        event(new OrderStatusChanged($order->fresh(), 'cancelled'));
                    } catch (\Throwable $th) {
                        Log::warning("ExpireStalePendingOrders: broadcast failed #{$order->id} — {$th->getMessage()}");
                    }
                });

                $this->line("✔ បានលុបចោល order #{$order->id} (stale)");
            } catch (\Throwable $th) {
                Log::error("ExpireStalePendingOrders: order #{$order->id} — {$th->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}