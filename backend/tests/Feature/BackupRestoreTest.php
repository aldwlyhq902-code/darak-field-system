<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Services\BackupService;
use Illuminate\Support\Facades\File;
use Tests\DarakTestCase;
use ZipArchive;

/**
 * Acceptance criterion 8. The point is not that a backup file exists — it is that
 * a restore reproduces the data, proven by record counts and file hashes. A backup
 * nobody has restored is a guess, and the day you need it is the worst possible
 * time to discover it was wrong.
 */
class BackupRestoreTest extends DarakTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/backups-' . uniqid());
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function service(): BackupService
    {
        return new BackupService($this->root);
    }

    public function test_backup_records_row_counts_for_every_verified_table(): void
    {
        $result = $this->service()->create('test');

        $this->assertFileExists($result['path']);

        $counts = $result['manifest']['counts'];

        $this->assertSame(Client::count(), $counts['clients']);
        $this->assertSame(3, $counts['users']);
        $this->assertArrayHasKey('visits', $counts);
        $this->assertArrayHasKey('audit_logs', $counts);
    }

    public function test_a_fresh_backup_verifies_against_the_live_database(): void
    {
        $result = $this->service()->create();

        $verification = $this->service()->verify($result['path']);

        $this->assertTrue($verification['ok']);
        $this->assertSame([], $verification['mismatched_counts']);
    }

    public function test_verification_detects_data_added_after_the_backup(): void
    {
        $result = $this->service()->create();

        Client::create(['name' => 'عميل بعد النسخة', 'category' => 'cafe']);

        $verification = $this->service()->verify($result['path']);

        $this->assertFalse($verification['ok']);
        $this->assertArrayHasKey('clients', $verification['mismatched_counts']);
        $this->assertSame(
            $verification['mismatched_counts']['clients']['expected'] + 1,
            $verification['mismatched_counts']['clients']['actual'],
        );
    }

    public function test_evidence_files_are_archived_with_their_hashes(): void
    {
        $evidenceDir = storage_path('app/private/media/visits/1');
        File::ensureDirectoryExists($evidenceDir);
        File::put($evidenceDir . '/photo.jpg', 'fake-photo-bytes');

        $result = $this->service()->create();

        $entry = collect(array_keys($result['manifest']['files']))
            ->first(fn ($path) => str_contains($path, 'photo.jpg'));

        $this->assertNotNull($entry, 'evidence must be inside the archive');
        $this->assertSame(hash('sha256', 'fake-photo-bytes'), $result['manifest']['files'][$entry]);

        File::deleteDirectory(storage_path('app/private/media'));
    }

    public function test_restore_writes_evidence_files_back(): void
    {
        $evidenceDir = storage_path('app/private/media/visits/9');
        File::ensureDirectoryExists($evidenceDir);
        File::put($evidenceDir . '/before.jpg', 'original-content');

        $result = $this->service()->create();

        $target = $this->root . '/restored';
        $restore = $this->service()->restore($result['path'], $target);

        $this->assertGreaterThan(0, $restore['restored_files']);
        $this->assertSame('original-content', File::get($target . '/media/visits/9/before.jpg'));

        File::deleteDirectory(storage_path('app/private/media'));
    }

    public function test_a_corrupted_archive_is_refused_rather_than_silently_restored(): void
    {
        $evidenceDir = storage_path('app/private/media/visits/5');
        File::ensureDirectoryExists($evidenceDir);
        File::put($evidenceDir . '/x.jpg', 'good-bytes');

        $result = $this->service()->create();

        // Tamper with the payload while leaving the manifest hash untouched —
        // exactly what a damaged transfer looks like.
        $zip = new ZipArchive();
        $zip->open($result['path']);
        $entry = collect(array_keys($result['manifest']['files']))->first(fn ($p) => str_contains($p, 'x.jpg'));
        $zip->addFromString($entry, 'tampered-bytes');
        $zip->close();

        $verification = $this->service()->verify($result['path']);
        $this->assertFalse($verification['ok']);
        $this->assertContains($entry, $verification['corrupt_files']);

        $this->expectExceptionMessage('Hash mismatch');
        $this->service()->restore($result['path'], $this->root . '/restored');

        File::deleteDirectory(storage_path('app/private/media'));
    }

    public function test_backup_command_runs_and_self_verifies(): void
    {
        config(['darak.backup_path' => $this->root]);

        $this->artisan('darak:backup', ['--label' => 'cli'])
            ->assertSuccessful();

        $this->assertNotEmpty(File::glob($this->root . '/darak-backup-*-cli.zip'));
    }

    public function test_restore_command_refuses_to_write_without_force(): void
    {
        config(['darak.backup_path' => $this->root]);

        $result = $this->service()->create();

        $this->artisan('darak:restore', ['archive' => $result['path']])
            ->expectsOutputToContain('--force')
            ->assertFailed();
    }

    public function test_restore_command_can_verify_without_writing(): void
    {
        config(['darak.backup_path' => $this->root]);

        $result = $this->service()->create();

        $this->artisan('darak:restore', ['archive' => $result['path'], '--verify-only' => true])
            ->assertSuccessful();
    }
}
