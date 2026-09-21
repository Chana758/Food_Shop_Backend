<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard: if duplicate transaction_ref values already exist (from
        // before this fix), the migration will fail with a duplicate-key
        // error. Surface that clearly instead of a raw SQL error.
        $duplicates = DB::table('payments')
            ->select('transaction_ref')
            ->whereNotNull('transaction_ref')
            ->groupBy('transaction_ref')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('transaction_ref');

        if ($duplicates->isNotEmpty()) {
            throw new \RuntimeException(
                'Cannot add unique index: duplicate transaction_ref values already exist: '
                . $duplicates->implode(', ')
                . '. Resolve these rows manually before re-running this migration.'
            );
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->unique('transaction_ref');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['transaction_ref']);
        });
    }
};