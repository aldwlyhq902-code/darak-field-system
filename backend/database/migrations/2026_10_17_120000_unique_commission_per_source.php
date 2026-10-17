<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One commission per policy per source document — enforced by the database.
 *
 * The nightly generator checked "already accrued?" in PHP; the manual entry
 * form did not check at all, so a user with the admin permission could accrue
 * the same contract, visit or receipt again and again. The source that counts
 * depends on the policy's basis: the visit, the receipt, or the contract alone.
 */
return new class extends Migration
{
    private const INDEXES = [
        'commission_entries_unique_visit' => ['visit_id', 'visit_id IS NOT NULL'],
        'commission_entries_unique_payment' => ['payment_id', 'payment_id IS NOT NULL'],
        'commission_entries_unique_contract' => ['contract_id', 'contract_id IS NOT NULL AND payment_id IS NULL AND visit_id IS NULL'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$column, $where]) {
            $duplicates = DB::table('commission_entries')
                ->select('commission_rule_id', $column)
                ->where('entry_type', 'commission')
                ->whereRaw($where)
                ->groupBy('commission_rule_id', $column)
                ->havingRaw('COUNT(*) > 1')
                ->count();

            // Commission rows are money already owed or paid: never deleted here.
            // Reverse the duplicates from the commissions page, then migrate.
            if ($duplicates > 0) {
                throw new RuntimeException("commission_entries has {$duplicates} duplicated ({$column}) group(s); reverse the extra entries before running this migration.");
            }

            DB::statement("CREATE UNIQUE INDEX {$name} ON commission_entries (commission_rule_id, {$column}) WHERE entry_type = 'commission' AND {$where}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
