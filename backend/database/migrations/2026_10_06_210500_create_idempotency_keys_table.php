<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remembered responses for mutating API calls that carry an Idempotency-Key.
 * A phone that loses the response to "accept custody" retries with the same
 * key and gets the same answer instead of a second side effect. The unique
 * (user_id, idempotency_key) index is what makes a concurrent duplicate lose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('method', 10);
            $table->string('path', 512);
            $table->char('request_hash', 64);
            $table->string('state', 16)->default('processing'); // processing|completed
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->string('response_content_type', 128)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index('expires_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE idempotency_keys ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
