<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A count from the phone is a proposal, not a write-off.
 *
 * Completing a stocktake on the phone posted every variance straight to the
 * books, and the phone was shown the expected quantity while counting: take N
 * parts, count expected − N, complete, and the shortfall vanished as an
 * "adjustment". Now the phone submits (status `submitted`), and a back-office
 * user other than the counter approves the variances in the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocktake_sessions', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stocktake_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn('submitted_at');
        });
    }
};
