<?php

namespace Tests\Feature;

use App\Models\EmployeeDocument;
use App\Models\MediaFile;
use App\Models\SalesLead;
use App\Models\SalesLeadAttachment;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Services\PhotoStamper;
use App\Services\VisitReportBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/** Private uploads follow darak.files_disk instead of a hard-coded 'local'. */
class FilesDiskTest extends DarakTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['darak.files_disk' => 'evidence']);
        Storage::fake('evidence');
        Storage::fake('local');
    }

    public function test_documents_are_stored_on_and_served_from_the_configured_disk(): void
    {
        $this->actingAs($this->owner, 'web')->post(route('panel.hr.document', $this->technician), [
            'type' => 'iqama', 'document_number' => '2000000000',
            'expires_on' => now()->addYear()->toDateString(),
            'file' => UploadedFile::fake()->create('iqama.pdf', 40, 'application/pdf'),
        ])->assertRedirect()->assertSessionHas('ok');
        $employeeDocument = EmployeeDocument::firstOrFail();
        Storage::disk('evidence')->assertExists($employeeDocument->file_path);
        Storage::disk('local')->assertMissing($employeeDocument->file_path);
        $this->get(route('panel.hr.document.download', $employeeDocument))->assertOk();

        $vehicle = Vehicle::where('assigned_user_id', $this->technician->id)->firstOrFail();
        $this->post(route('panel.fleet.document', $vehicle), [
            'type' => 'insurance', 'document_number' => 'POL-1',
            'expires_on' => now()->addYear()->toDateString(),
            'file' => UploadedFile::fake()->create('policy.pdf', 40, 'application/pdf'),
        ])->assertRedirect();
        $vehicleDocument = VehicleDocument::firstOrFail();
        Storage::disk('evidence')->assertExists($vehicleDocument->file_path);
        $this->get(route('panel.fleet.document.download', $vehicleDocument))->assertOk();

        $lead = SalesLead::create([
            'lead_no' => 'LEAD-DISK', 'company_name' => 'Disk Lead', 'stage' => 'new',
            'estimated_value' => 0, 'owner_user_id' => $this->owner->id,
            'operating_branch_id' => $this->operatingBranch->id,
        ]);
        $this->post(route('sales.leads.attachment', $lead), [
            'file' => UploadedFile::fake()->create('brief.pdf', 20, 'application/pdf'),
        ])->assertRedirect();
        $attachment = SalesLeadAttachment::firstOrFail();
        $this->assertSame('evidence', $attachment->disk, 'the disk is recorded per file');
        Storage::disk('evidence')->assertExists($attachment->path);
    }

    public function test_photo_stamp_and_report_use_the_disk_api_not_a_local_path(): void
    {
        $image = imagecreatetruecolor(64, 48);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);
        Storage::disk('evidence')->put('media/originals/photo.jpg', $jpeg);

        $media = MediaFile::create([
            'visit_id' => $this->visit->id, 'client_media_id' => (string) Str::uuid(), 'kind' => 'photo',
            'mime' => 'image/jpeg', 'original_path' => 'media/originals/photo.jpg',
            'original_hash' => hash('sha256', $jpeg), 'total_bytes' => strlen($jpeg),
            'uploaded_bytes' => strlen($jpeg), 'upload_state' => 'complete',
            'captured_at' => now(), 'declared_source' => 'camera',
        ]);

        $derived = app(PhotoStamper::class)->stamp($media);

        $this->assertNotNull($derived);
        Storage::disk('evidence')->assertExists($derived);
        Storage::disk('local')->assertMissing($derived);
        $this->assertStringStartsWith('data:image/jpeg;base64,', app(VisitReportBuilder::class)->imageDataUri($derived));
    }
}
