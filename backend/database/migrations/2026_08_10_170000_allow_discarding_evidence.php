<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A way out for evidence that will never upload.
 *
 * A failed file blocked the close, and there was no endpoint to remove it —
 * retaking simply added a second row while the first kept raising UPLOADS_FAILED.
 * The visit became permanently un-closable through the app, which is exactly the
 * class of defect the close gate exists to prevent, inverted.
 *
 * Discarding is recorded, never deleted: who dropped which file and why is part
 * of the audit trail, and a silent delete would be a gap an auditor could not
 * distinguish from evidence that never existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->timestamp('discarded_at')->nullable()->after('upload_state');
            $table->string('discard_reason', 190)->nullable()->after('discarded_at');
            $table->foreignId('discarded_by')->nullable()->after('discard_reason')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->after('discarded_by')
                ->constrained('media_files')->nullOnDelete();
            $table->index(['visit_id', 'discarded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropIndex(['visit_id', 'discarded_at']);
            $table->dropConstrainedForeignId('superseded_by_id');
            $table->dropConstrainedForeignId('discarded_by');
            $table->dropColumn(['discarded_at', 'discard_reason']);
        });
    }
};
