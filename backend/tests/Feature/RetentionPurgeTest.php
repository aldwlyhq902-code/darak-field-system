<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EmergencyReport;
use App\Models\MediaFile;
use App\Models\ReportDispute;
use App\Models\Visit;
use App\Models\VisitEvent;
use App\Services\ReportImages;
use App\Services\RetentionPurgeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

class RetentionPurgeTest extends DarakTestCase
{
    private string $backupRoot;

    private string $logRoot;

    private Visit $heldVisit;

    private Visit $recentVisit;

    private MediaFile $photo;

    private MediaFile $signature;

    private MediaFile $heldPhoto;

    private MediaFile $recentPhoto;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->backupRoot = storage_path('framework/testing/retention-backups-'.uniqid());
        $this->logRoot = storage_path('framework/testing/retention-logs-'.uniqid());
        File::ensureDirectoryExists($this->backupRoot);
        File::ensureDirectoryExists($this->logRoot);
        $this->app->instance(RetentionPurgeService::class, new RetentionPurgeService($this->logRoot));

        config([
            'darak.backup_path' => $this->backupRoot,
            'darak.retention_purge_enabled' => true,
            'darak.privacy.retention_days' => [
                'visit_photos' => 30, 'emergency_reports' => 30, 'signatures_reports' => 30,
                'capture_coordinates' => 30, 'audit_security_logs' => 30, 'backups' => 30,
            ],
        ]);

        // Expired visit: closed 60 days ago, nothing holding it.
        $this->closeVisit($this->visit, 60);
        $this->visit->forceFill(['technician_lat' => 21.5811234, 'technician_lng' => 39.1651234])->save();
        $this->photo = $this->media($this->visit, 'photo_before');
        $this->signature = $this->media($this->visit, 'signature');
        Storage::disk('local')->put("reports/visits/visit-{$this->visit->id}.pdf", '%PDF');
        VisitEvent::create([
            'visit_id' => $this->visit->id, 'client_event_id' => (string) Str::uuid(), 'event_type' => 'visit.transition',
            'server_received_at' => now()->subDays(61), 'lat' => 21.58, 'lng' => 39.16,
        ]);

        // Equally old, but under an open dispute.
        $this->heldVisit = $this->newVisit();
        $this->closeVisit($this->heldVisit, 60);
        $this->heldPhoto = $this->media($this->heldVisit, 'photo_after');
        ReportDispute::create([
            'public_reference' => (string) Str::uuid(), 'visit_id' => $this->heldVisit->id,
            'category' => 'work', 'description' => 'Disputed', 'status' => 'reviewed',
        ]);

        // Closed recently: inside the retention period.
        $this->recentVisit = $this->newVisit();
        $this->closeVisit($this->recentVisit, 5);
        $this->recentPhoto = $this->media($this->recentVisit, 'photo_before');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupRoot);
        File::deleteDirectory($this->logRoot);
        parent::tearDown();
    }

    public function test_dry_run_is_the_default_and_changes_nothing(): void
    {
        $this->artisan('darak:retention-purge')
            ->expectsOutputToContain('dry run')
            ->assertSuccessful();

        Storage::disk('local')->assertExists($this->photo->original_path);
        Storage::disk('local')->assertExists("reports/visits/visit-{$this->visit->id}.pdf");
        $this->assertNotNull($this->photo->fresh()->lat);
        $this->assertSame(0, AuditLog::where('action', 'retention.purge_executed')->count());

        $report = app(RetentionPurgeService::class)->run(false);
        $this->assertSame(2, $report['visit_photos']['candidates']);
        $this->assertSame(1, $report['visit_photos']['held']);
        $this->assertSame(0, $report['visit_photos']['purged']);
        $this->assertSame(2, $report['signatures_reports']['candidates']); // signature + report PDF
    }

    public function test_execute_purges_expired_evidence_and_respects_holds(): void
    {
        $emergency = $this->emergency(EmergencyReport::STATUS_REJECTED, 60);
        $openEmergency = $this->emergency(EmergencyReport::STATUS_NEW, 60);
        $this->oldSecurityArtifacts();
        File::put($oldArchive = $this->backupRoot.'/darak-backup-20240101-023000.zip', 'x');
        File::put($newestArchive = $this->backupRoot.'/darak-backup-20240201-023000.zip', 'x');

        $this->artisan('darak:retention-purge', ['--execute' => true])->assertSuccessful();

        // Expired visit: bytes gone, row kept with its hash.
        $photo = $this->photo->fresh();
        $this->assertNull($photo->original_path);
        $this->assertNull($photo->derived_path);
        $this->assertNotNull($photo->purged_at);
        $this->assertSame(str_repeat('a', 64), $photo->original_hash);
        Storage::disk('local')->assertMissing($this->photo->original_path);
        Storage::disk('local')->assertMissing($this->signature->original_path);
        Storage::disk('local')->assertMissing("reports/visits/visit-{$this->visit->id}.pdf");
        Storage::disk('local')->assertMissing(ReportImages::cachePathFor($this->photo->original_path));
        Storage::disk('local')->assertMissing(ReportImages::cachePathFor($this->photo->derived_path));
        Storage::disk('local')->assertMissing(ReportImages::cachePathFor($this->signature->original_path));
        Storage::disk('local')->assertExists(ReportImages::cachePathFor($this->heldPhoto->original_path));

        // Coordinates nulled, the rest of the row kept.
        $this->assertNull($photo->lat);
        $this->assertNull($photo->lng);
        $this->assertSame('photo_before', $photo->kind);
        $this->assertNull($this->visit->fresh()->technician_lat);
        $this->assertNull(VisitEvent::where('visit_id', $this->visit->id)->first()->lat);

        // Held and recent visits untouched.
        Storage::disk('local')->assertExists($this->heldPhoto->original_path);
        $this->assertNotNull($this->heldPhoto->fresh()->lat);
        Storage::disk('local')->assertExists($this->recentPhoto->original_path);
        $this->assertNotNull($this->recentPhoto->fresh()->lat);

        // Emergency: closed one anonymised, open one untouched.
        $purged = $emergency->fresh();
        $this->assertNotSame('Reporter Name', $purged->reporter_name);
        $this->assertSame('', $purged->reporter_phone);
        $this->assertNull($purged->photo_path);
        $this->assertNotNull($purged->purged_at);
        Storage::disk('local')->assertMissing('emergency/'.$emergency->id.'.jpg');
        $this->assertSame('0550000000', $openEmergency->fresh()->reporter_phone);

        // Security artefacts.
        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertFileDoesNotExist($this->logRoot.'/laravel-2024-01-01.log');
        $this->assertFileExists($this->logRoot.'/laravel.log');

        // Backups: old archive gone, the newest kept however old.
        $this->assertFileDoesNotExist($oldArchive);
        $this->assertFileExists($newestArchive);

        $audit = AuditLog::where('action', 'retention.purge_executed')->sole();
        $this->assertSame(1, $audit->after['categories']['visit_photos']['purged']);
        $this->assertSame(1, $audit->after['categories']['visit_photos']['held']);
        $this->assertSame(1, $audit->after['categories']['backups']['held']);
    }

    public function test_execute_is_refused_until_the_owner_enables_it(): void
    {
        config(['darak.retention_purge_enabled' => false]);

        $this->artisan('darak:retention-purge', ['--execute' => true])
            ->expectsOutputToContain('DARAK_RETENTION_PURGE_ENABLED')
            ->assertFailed();

        Storage::disk('local')->assertExists($this->photo->original_path);
    }

    public function test_unconfigured_categories_are_skipped(): void
    {
        config(['darak.privacy.retention_days.visit_photos' => null]);

        $this->artisan('darak:retention-purge', ['--execute' => true])->assertSuccessful();

        Storage::disk('local')->assertExists($this->photo->original_path);
        Storage::disk('local')->assertMissing($this->signature->original_path);
    }

    public function test_unresolved_complaints_and_arrears_hold_evidence(): void
    {
        DB::table('visit_feedback')->insert([
            'visit_id' => $this->visit->id, 'rating' => 1, 'resolution_confirmed' => false,
            'is_complaint' => true, 'status' => 'new', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $report = app(RetentionPurgeService::class)->run(true);
        $this->assertSame(0, $report['visit_photos']['purged']);
        Storage::disk('local')->assertExists($this->photo->original_path);

        DB::table('visit_feedback')->where('visit_id', $this->visit->id)->update(['status' => 'resolved']);
        DB::table('contract_installments')->insert([
            'contract_id' => $this->contract->id, 'installment_no' => 1, 'due_on' => now()->subDays(10)->toDateString(),
            'amount' => 100, 'vat_amount' => 15, 'total_amount' => 115, 'paid_amount' => 0, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $report = app(RetentionPurgeService::class)->run(true);
        $this->assertSame(0, $report['visit_photos']['purged']);
        $this->assertSame(2, $report['visit_photos']['held']);
    }

    private function closeVisit(Visit $visit, int $daysAgo): void
    {
        $visit->forceFill([
            'state' => Visit::STATE_COMPLETED,
            'closed_at' => now()->subDays($daysAgo),
            'state_changed_at' => now()->subDays($daysAgo),
        ])->save();
    }

    private function newVisit(): Visit
    {
        return Visit::create([
            'work_order_id' => $this->visit->work_order_id,
            'site_id' => $this->site->id,
            'assigned_user_id' => $this->technician->id,
            'scheduled_start' => now()->subDays(70),
            'scheduled_end' => now()->subDays(70)->addHours(2),
            'state' => Visit::STATE_SCHEDULED,
            'state_changed_at' => now()->subDays(70),
        ]);
    }

    private function media(Visit $visit, string $kind): MediaFile
    {
        $original = "media/visits/{$visit->id}/{$kind}.jpg";
        $derived = $kind === 'signature' ? null : "media/visits/{$visit->id}/{$kind}-stamped.jpg";
        Storage::disk('local')->put($original, 'bytes');
        // The visit report's cached, downscaled copies (ReportImages).
        Storage::disk('local')->put(ReportImages::cachePathFor($original), 'report copy');
        if ($derived !== null) {
            Storage::disk('local')->put($derived, 'stamped');
            Storage::disk('local')->put(ReportImages::cachePathFor($derived), 'report copy');
        }

        return MediaFile::create([
            'visit_id' => $visit->id, 'client_media_id' => (string) Str::uuid(), 'kind' => $kind,
            'mime' => 'image/jpeg', 'original_path' => $original, 'original_hash' => str_repeat('a', 64),
            'derived_path' => $derived, 'upload_state' => 'complete', 'lat' => 21.5810123, 'lng' => 39.1650123,
        ]);
    }

    private function emergency(string $status, int $daysAgo): EmergencyReport
    {
        $report = EmergencyReport::create([
            'public_reference' => (string) Str::uuid(), 'site_id' => $this->site->id,
            'reporter_name' => 'Reporter Name', 'reporter_phone' => '0550000000', 'category' => 'leak',
            'description' => 'Water everywhere near apartment 4', 'status' => $status,
        ]);
        $path = 'emergency/'.$report->id.'.jpg';
        Storage::disk('local')->put($path, 'photo');
        DB::table('emergency_reports')->where('id', $report->id)->update([
            'photo_path' => $path, 'created_at' => now()->subDays($daysAgo),
        ]);

        return $report->fresh();
    }

    private function oldSecurityArtifacts(): void
    {
        DB::table('sessions')->insert([
            'id' => Str::random(40), 'user_id' => $this->owner->id, 'ip_address' => '203.0.113.9',
            'user_agent' => 'Mozilla', 'payload' => '', 'last_activity' => now()->subDays(45)->timestamp,
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => 'owner@test.local', 'token' => 'x', 'created_at' => now()->subDays(45),
        ]);
        File::put($this->logRoot.'/laravel-2024-01-01.log', 'old');
        touch($this->logRoot.'/laravel-2024-01-01.log', now()->subDays(90)->timestamp);
        File::put($this->logRoot.'/laravel.log', 'today');
    }
}
