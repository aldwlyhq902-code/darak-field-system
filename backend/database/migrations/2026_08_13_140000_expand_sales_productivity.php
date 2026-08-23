<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->string('source', 32)->default('direct')->after('stage');
            $table->string('campaign_name')->nullable()->after('source');
            $table->unsignedTinyInteger('probability_percent')->default(10)->after('estimated_value');
            $table->date('expected_close_on')->nullable()->after('probability_percent');
            $table->timestamp('last_contacted_at')->nullable()->after('next_action_on');
            $table->timestamp('converted_at')->nullable()->after('converted_client_id');
            $table->index(['owner_user_id', 'last_contacted_at'], 'sales_leads_owner_contact_idx');
            $table->index(['source', 'created_at'], 'sales_leads_source_date_idx');
        });

        Schema::create('sales_lead_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 16);
            $table->string('disk', 24)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 96);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
            $table->index(['sales_lead_id', 'kind']);
        });

        Schema::create('sales_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->unsignedInteger('calls_target')->default(40);
            $table->unsignedInteger('meetings_target')->default(12);
            $table->unsignedInteger('proposals_target')->default(8);
            $table->decimal('won_value_target', 14, 2)->default(100000);
            $table->decimal('collections_target', 14, 2)->default(75000);
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'month']);
        });

        Schema::create('web_push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->text('public_key');
            $table->text('auth_token');
            $table->string('content_encoding', 16)->default('aes128gcm');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_subscriptions');
        Schema::dropIfExists('sales_targets');
        Schema::dropIfExists('sales_lead_attachments');
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropIndex('sales_leads_owner_contact_idx');
            $table->dropIndex('sales_leads_source_date_idx');
            $table->dropColumn(['source', 'campaign_name', 'probability_percent', 'expected_close_on', 'last_contacted_at', 'converted_at']);
        });
    }
};
