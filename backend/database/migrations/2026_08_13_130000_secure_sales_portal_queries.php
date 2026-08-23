<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->foreignId('operating_branch_id')->nullable()->after('owner_user_id')->constrained()->nullOnDelete();
            $table->index(['owner_user_id', 'stage', 'next_action_on'], 'sales_leads_owner_pipeline_idx');
        });
        Schema::table('sales_activities', function (Blueprint $table): void {
            $table->index(['user_id', 'occurred_at'], 'sales_activities_user_date_idx');
        });
        Schema::table('quotations', function (Blueprint $table): void {
            $table->index(['created_by', 'status', 'created_at'], 'quotations_creator_status_date_idx');
        });

        DB::table('sales_leads')->whereNotNull('owner_user_id')->update([
            'operating_branch_id' => DB::raw('(SELECT operating_branch_id FROM users WHERE users.id = sales_leads.owner_user_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('quotations', fn (Blueprint $table) => $table->dropIndex('quotations_creator_status_date_idx'));
        Schema::table('sales_activities', fn (Blueprint $table) => $table->dropIndex('sales_activities_user_date_idx'));
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropIndex('sales_leads_owner_pipeline_idx');
            $table->dropConstrainedForeignId('operating_branch_id');
        });
    }
};
