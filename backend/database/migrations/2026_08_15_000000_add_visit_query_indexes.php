<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->index('scheduled_start', 'visits_scheduled_start_index');
            $table->index(['assigned_user_id', 'scheduled_start'], 'visits_assignee_schedule_index');
            $table->index(['state', 'scheduled_start'], 'visits_state_schedule_index');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropIndex('visits_scheduled_start_index');
            $table->dropIndex('visits_assignee_schedule_index');
            $table->dropIndex('visits_state_schedule_index');
        });
    }
};
