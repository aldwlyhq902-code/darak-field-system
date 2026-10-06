<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD v1.2 binding correction: a commission is ACCRUED when the money is
 * collected, EARNED once the clawback window passes without a refund or
 * cancellation, and PAID only when the owner records a payout with a reference.
 * A reversal after payout is a negative adjustment that can only offset future
 * commissions — it is never deducted from wages and never makes a payout negative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('reference', 96);
            $table->date('paid_on');
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('offset_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);
            $table->json('offsets')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'paid_on']);
        });

        Schema::table('commission_entries', function (Blueprint $table): void {
            $table->string('entry_type', 16)->default('commission');
            $table->foreignId('reverses_entry_id')->nullable()->constrained('commission_entries')->restrictOnDelete();
            $table->timestamp('accrued_at')->nullable();
            $table->timestamp('earned_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 190)->nullable();
            $table->foreignId('commission_payout_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('offset_amount', 12, 2)->default(0);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'accrued_at']);
        });

        // Existing entries were all "pending": they are accrued from the moment
        // they were generated, so the clawback window runs from created_at.
        DB::table('commission_entries')->where('status', 'pending')
            ->update(['status' => 'accrued', 'accrued_at' => DB::raw('created_at')]);

        Schema::table('commission_entries', function (Blueprint $table): void {
            $table->string('status', 16)->default('accrued')->change();
        });

        // A refunded receipt stays on record (receipts are never deleted); the
        // refund is what voids its commission.
        Schema::table('payments', function (Blueprint $table): void {
            $table->timestamp('refunded_at')->nullable();
            $table->string('refund_reason', 500)->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE commission_payouts ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('refunded_by');
            $table->dropColumn(['refunded_at', 'refund_reason']);
        });

        DB::table('commission_entries')->where('entry_type', 'adjustment')->delete();
        DB::table('commission_entries')->whereIn('status', ['accrued', 'earned'])->update(['status' => 'pending']);

        Schema::table('commission_entries', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['status', 'accrued_at']);
            $table->dropConstrainedForeignId('commission_payout_id');
            $table->dropConstrainedForeignId('reverses_entry_id');
            $table->dropColumn(['entry_type', 'accrued_at', 'earned_at', 'paid_at', 'reversed_at', 'reversal_reason', 'offset_amount']);
            $table->string('status', 16)->default('pending')->change();
        });

        Schema::dropIfExists('commission_payouts');
    }
};
