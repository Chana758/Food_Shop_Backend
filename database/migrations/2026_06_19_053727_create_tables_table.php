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
    Schema::create('tables', function (Blueprint $table) {
        $table->id();
        $table->string('name'); // ឈ្មោះតុ ដូចជា "តុលេខ ១"
        $table->integer('capacity'); // ចំនួនមនុស្ស
        $table->string('status')->default('available'); // available, occupied, reserved
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
