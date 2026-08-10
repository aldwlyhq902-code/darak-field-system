<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence storage. Two corrections from the adversarial review are baked in here:
 *  - The ORIGINAL capture is preserved with its hash; the stamped copy used in the
 *    report is a derivative. Keeping only the stamped file destroys the audit trail.
 *  - "Camera only" is a device policy, not proof. We record the declared source and
 *    can flag gallery uploads, but we do not pretend it is tamper-proof.
 *
 * Uploads are resumable: a connection dropped at 60% resumes rather than restarting,
 * and the hash must match once complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('client_media_id')->unique();      // device-generated, idempotent
            $table->string('kind', 32);                     // photo_before|photo_after|signature|audio
            $table->string('mime', 96)->nullable();

            $table->string('original_path')->nullable();     // private disk
            $table->string('original_hash', 64)->nullable(); // sha256 verified after upload
            $table->string('derived_path')->nullable();      // stamped copy used in the PDF

            $table->unsignedBigInteger('total_bytes')->nullable();
            $table->unsignedBigInteger('uploaded_bytes')->default(0);
            $table->string('upload_state', 16)->default('pending'); // pending|uploading|complete|failed
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamp('captured_at')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('declared_source', 16)->default('camera'); // camera|gallery
            $table->timestamps();

            $table->index(['visit_id', 'kind']);
            $table->index('upload_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
