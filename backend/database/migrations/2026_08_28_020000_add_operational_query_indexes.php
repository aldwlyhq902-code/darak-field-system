<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_moves', function (Blueprint $table): void {
            $table->index(['to_location_id', 'part_id'], 'stock_moves_to_location_part_idx');
            $table->index(['from_location_id', 'part_id'], 'stock_moves_from_location_part_idx');
            $table->index(['move_type', 'created_at', 'part_id'], 'stock_moves_type_date_part_idx');
        });

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->index(['asset_id', 'fault_code', 'reported_at'], 'work_orders_asset_fault_date_idx');
        });

        Schema::table('vehicle_outages', function (Blueprint $table): void {
            $table->index(['vehicle_id', 'status', 'starts_at'], 'vehicle_outages_vehicle_status_start_idx');
        });

        Schema::table('notification_messages', function (Blueprint $table): void {
            $table->index(['status', 'sent_at'], 'notification_messages_status_sent_idx');
        });
    }

    public function down(): void
    {
        Schema::table('notification_messages', fn (Blueprint $table) => $table->dropIndex('notification_messages_status_sent_idx'));
        Schema::table('vehicle_outages', fn (Blueprint $table) => $table->dropIndex('vehicle_outages_vehicle_status_start_idx'));
        Schema::table('work_orders', fn (Blueprint $table) => $table->dropIndex('work_orders_asset_fault_date_idx'));
        Schema::table('stock_moves', function (Blueprint $table): void {
            $table->dropIndex('stock_moves_to_location_part_idx');
            $table->dropIndex('stock_moves_from_location_part_idx');
            $table->dropIndex('stock_moves_type_date_part_idx');
        });
    }
};
