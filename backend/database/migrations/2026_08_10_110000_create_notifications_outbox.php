<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three notifications only in the MVP (PRD v1.2 §2): visit assigned, SLA at risk,
 * report ready. A general multi-channel template engine is backlog.
 *
 * The outbox pattern matters more than the channel: a message is committed to the
 * database in the same transaction as the thing that caused it, then delivered.
 * Nothing can be "sent" for an event that did not happen, and nothing silently
 * fails to send — a message that exhausts its retries lands in a dead-letter list
 * the supervisor actually sees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_messages', function (Blueprint $table) {
            $table->id();
            $table->string('type', 48);                 // visit.assigned|sla.at_risk|report.ready
            $table->string('channel', 24);              // in_app|whatsapp_manual|mail
            $table->string('recipient_kind', 24);       // technician|client|supervisor
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 32)->nullable();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 190)->nullable();
            $table->text('body');
            $table->json('context')->nullable();
            $table->string('status', 16)->default('queued'); // queued|sent|failed|dead
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();   // backoff
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('idempotency_key', 190)->unique();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_messages');
    }
};
