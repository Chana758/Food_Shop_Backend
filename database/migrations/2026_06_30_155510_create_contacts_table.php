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
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();

            // Guests can send messages, so user_id is nullable
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('subject')->nullable();
            $table->text('message');

            // Tracking the status of the contact request
            $table->enum('status', [
                'unread',
                'read',
                'replied',
            ])->default('unread');

            // Tracks which admin/staff member replied
            $table->foreignId('replied_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->text('reply_message')->nullable();
            $table->timestamp('replied_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};