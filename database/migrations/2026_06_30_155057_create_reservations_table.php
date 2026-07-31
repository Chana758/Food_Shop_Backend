<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            // User who made the reservation (Required)
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            // Associated table
            $table->foreignId('table_id')
                  ->constrained('tables')
                  ->onDelete('cascade');

            $table->unsignedInteger('guest_count');
            $table->dateTime('reserved_at'); // Date and time of reservation

            // Reservation status flow
            $table->enum('status', [
                'pending',
                'confirmed',
                'rejected',
                'completed',
                'cancelled',
            ])->default('pending');

            $table->text('notes')->nullable();

            // Tracks which admin/staff member processed the reservation
            $table->foreignId('confirmed_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};