<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee numbers are unique per company. A global unique key let one company
 * probe another's numbers (and blocked both from using the same scheme).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table): void {
            $table->foreignId('operating_company_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
        });
        DB::table('employee_profiles')->update([
            'operating_company_id' => DB::raw('(SELECT operating_company_id FROM users WHERE users.id = employee_profiles.user_id)'),
        ]);
        Schema::table('employee_profiles', function (Blueprint $table): void {
            $table->dropUnique(['employee_no']);
            $table->unique(['operating_company_id', 'employee_no']);
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table): void {
            $table->dropUnique(['operating_company_id', 'employee_no']);
            $table->dropConstrainedForeignId('operating_company_id');
            $table->unique(['employee_no']);
        });
    }
};
