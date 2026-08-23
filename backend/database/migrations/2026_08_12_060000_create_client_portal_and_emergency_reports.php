<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('emergency_public_token', 64)->nullable()->unique()->after('qr_code');
            $table->text('emergency_qr_secret')->nullable()->after('emergency_public_token');
        });

        DB::table('sites')->orderBy('id')->each(function (object $site): void {
            $secret = Str::random(48);
            DB::table('sites')->where('id', $site->id)->update([
                'emergency_public_token' => hash('sha256', $secret),
                'emergency_qr_secret' => Crypt::encryptString($secret),
            ]);
        });

        Schema::create('client_portal_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 32)->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->index(['client_id', 'is_active']);
        });

        Schema::create('emergency_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_reference')->unique();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_portal_user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reporter_name', 100);
            $table->string('reporter_phone', 32);
            $table->string('reporter_role', 64)->nullable();
            $table->string('category', 32);
            $table->string('severity', 16)->default('urgent');
            $table->text('description');
            $table->string('photo_path')->nullable();
            $table->string('photo_mime', 64)->nullable();
            $table->string('photo_sha256', 64)->nullable();
            $table->string('status', 24)->default('new');
            $table->string('source', 24)->default('site_qr');
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['site_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_reports');
        Schema::dropIfExists('client_portal_users');
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['emergency_public_token', 'emergency_qr_secret']);
        });
    }
};
