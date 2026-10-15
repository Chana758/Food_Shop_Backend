<?php

// database/migrations/xxxx_add_delivery_fields_to_orders_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_order_type_check');

        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_type')->change(); 
            $table->foreignId('rider_id')->nullable()->constrained('riders')->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('delivery_address')->nullable();
            $table->string('delivery_status')->nullable()->default('unassigned');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['rider_id']);
            $table->dropColumn(['rider_id', 'customer_name', 'customer_phone', 'delivery_address', 'delivery_status']);
        });
    }
};
