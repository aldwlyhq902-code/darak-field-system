<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\DarakTestCase;

class OperationalHealthTest extends DarakTestCase
{
    private string $backupRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupRoot = storage_path('framework/testing/health-'.uniqid());
        File::ensureDirectoryExists($this->backupRoot);
        config([
            'darak.backup_path' => $this->backupRoot,
            'darak.monitoring.minimum_free_disk_bytes' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupRoot);
        parent::tearDown();
    }

    public function test_health_passes_with_fresh_scheduler_and_backup(): void
    {
        $this->artisan('darak:heartbeat')->assertSuccessful();
        File::put($this->backupRoot.'/darak-backup-test.zip', 'test');

        $this->artisan('darak:health', ['--json' => true])
            ->expectsOutputToContain('"ok":true')
            ->assertSuccessful();
    }

    public function test_health_fails_when_scheduler_and_backup_are_missing(): void
    {
        Cache::forget('darak:monitor:scheduler-heartbeat');

        $this->artisan('darak:health')
            ->expectsOutputToContain('heartbeat missing')
            ->expectsOutputToContain('no backup archive found')
            ->assertFailed();
    }

    public function test_health_fails_for_a_stale_backup(): void
    {
        $this->artisan('darak:heartbeat')->assertSuccessful();
        $archive = $this->backupRoot.'/darak-backup-old.zip';
        File::put($archive, 'test');
        touch($archive, now()->subHours(27)->timestamp);

        $this->artisan('darak:health')->assertFailed();
    }

    public function test_every_http_response_has_a_correlation_id(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $response->headers->get('X-Request-ID'),
        );
    }
}
