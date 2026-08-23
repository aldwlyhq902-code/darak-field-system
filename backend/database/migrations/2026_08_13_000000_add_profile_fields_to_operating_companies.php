<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operating_companies', function (Blueprint $table): void {
            $table->string('logo_path')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('whatsapp', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->nullable()->default('السعودية');
        });
    }

    public function down(): void
    {
        Schema::table('operating_companies', function (Blueprint $table): void {
            $table->dropColumn([
                'logo_path', 'phone', 'whatsapp', 'email', 'website',
                'address', 'city', 'postal_code', 'country',
            ]);
        });
    }
};
