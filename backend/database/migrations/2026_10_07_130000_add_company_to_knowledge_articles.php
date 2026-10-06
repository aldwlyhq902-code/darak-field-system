<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge-base articles (diagnoses, fixes, suggested parts) belong to the
 * company that wrote them. They were shown to every company's panel and
 * suggested to every company's technicians.
 *
 * Backfill: an article takes its author's company; with exactly one company
 * every remaining article is that company's. Any other article stays
 * company-less — visible only to platform admins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_articles', function (Blueprint $table): void {
            $table->foreignId('operating_company_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->index(['operating_company_id', 'asset_type', 'fault_code']);
        });

        DB::table('knowledge_articles')->whereNull('operating_company_id')->whereNotNull('created_by')->update([
            'operating_company_id' => DB::raw('(SELECT operating_company_id FROM users WHERE users.id = knowledge_articles.created_by)'),
        ]);
        $companyIds = DB::table('operating_companies')->pluck('id');
        if ($companyIds->count() === 1) {
            DB::table('knowledge_articles')->whereNull('operating_company_id')
                ->update(['operating_company_id' => $companyIds->first()]);
        }
    }

    public function down(): void
    {
        Schema::table('knowledge_articles', function (Blueprint $table): void {
            $table->dropIndex(['operating_company_id', 'asset_type', 'fault_code']);
            $table->dropConstrainedForeignId('operating_company_id');
        });
    }
};
