<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::table('products', function (Blueprint $table) {
        $table->string('sku')->unique()->nullable()->after('id');
        $table->decimal('discount_price', 10, 2)->nullable()->after('price');
        $table->boolean('is_active')->default(true)->after('stock_quantity');
        $table->boolean('is_featured')->default(false)->after('is_active');
        $table->integer('prep_time')->nullable()->after('is_featured');
    });
}

public function down()
{
    Schema::table('products', function (Blueprint $table) {
        $table->dropColumn(['sku', 'discount_price', 'is_active', 'is_featured', 'prep_time']);
    });
}
};
