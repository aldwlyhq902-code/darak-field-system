<?php

namespace Tests\Feature;

use App\Models\NotificationMessage;
use App\Services\BackupService;
use App\Services\OffsiteBackupService;
use App\Services\OpsAlertService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Tests\DarakTestCase;

/**
 * Rotation, off-site copy, heartbeats and alerting around the nightly backup.
 * rclone is never required: Process::fake stands in for it.
 */
class BackupOperationsTest extends DarakTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/backup-ops-'.uniqid());
        File::ensureDirectoryExists($this->root);
        config([
            'darak.backup_path' => $this->root,
            'darak.backup_rclone_remote' => null,
            'darak.rclone_path' => '/opt/rclone/bin/rclone',
            'darak.backup_heartbeat_url' => null,
            'darak.heartbeat_url' => null,
            'darak.backup_rotation' => ['daily' => 7, 'weekly' => 4, 'monthly' => 6],
        ]);
        Cache::forget(OffsiteBackupService::STATUS_CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_rotation_keeps_seven_daily_four_weekly_and_six_monthly_archives(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-06 03:00:00', config('app.timezone')));

        for ($day = Carbon::parse('2026-05-01'); $day->lte(Carbon::parse('2026-10-06')); $day = $day->addDay()) {
            File::put($this->root.'/darak-backup-'.$day->format('Ymd').'-023000.zip', 'x');
        }

        $result = (new BackupService($this->root))->rotate(7, 4, 6);

        $kept = collect($result['kept'])->map(fn (string $path): string => substr(basename($path), 13, 8))->sort()->values()->all();
        $this->assertSame([
            '20260531', '20260630', '20260731', '20260831', // monthly
            '20260920', '20260927',                          // weekly (ISO weeks 38, 39)
            '20260930', '20261001', '20261002', '20261003', '20261004', '20261005', '20261006', // daily
        ], $kept);
        $this->assertSame([], $result['failed']);
        $this->assertCount(count($result['kept']), File::glob($this->root.'/darak-backup-*.zip'));
    }

    public function test_rotation_never_deletes_the_newest_or_the_protected_archive(): void
    {
        File::put($old = $this->root.'/darak-backup-20250101-023000.zip', 'x');
        File::put($older = $this->root.'/darak-backup-20240101-023000.zip', 'x');
        File::put($newest = $this->root.'/darak-backup-20260101-023000.zip', 'x');

        $result = (new BackupService($this->root))->rotate(0, 0, 0, $old);

        $this->assertFileExists($newest);
        $this->assertFileExists($old);
        $this->assertFileDoesNotExist($older);
        $this->assertSame([basename($older)], array_map('basename', $result['deleted']));
    }

    public function test_backup_copies_off_site_with_an_argument_array_rotates_and_pings(): void
    {
        Process::fake(['*' => Process::result(output: '', exitCode: 0)]);
        Http::fake(['https://hc-ping.example.test/*' => Http::response('OK')]);
        config([
            'darak.backup_rclone_remote' => 'offsite:darak-backups/prod',
            'darak.backup_heartbeat_url' => 'https://hc-ping.example.test/backup-uuid',
            // Periods without archives do not use up slots, so a tight policy is
            // needed for the 2024 archive to fall outside it.
            'darak.backup_rotation' => ['daily' => 1, 'weekly' => 1, 'monthly' => 1],
        ]);
        File::put($stale = $this->root.'/darak-backup-20240101-023000.zip', 'x');

        $this->artisan('darak:backup')->assertSuccessful();

        $archive = collect(File::glob($this->root.'/darak-backup-*.zip'))->map(fn ($path) => basename($path))->sort()->last();
        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && basename((string) $process->command[2]) === $archive
            && array_replace($process->command, [2 => $archive]) === [
                '/opt/rclone/bin/rclone', 'copy', $archive, 'offsite:darak-backups/prod',
                '--retries', '3', '--low-level-retries', '10', '--stats', '0',
            ]);
        $this->assertTrue(Cache::get(OffsiteBackupService::STATUS_CACHE_KEY)['ok']);
        $this->assertFileDoesNotExist($stale, 'rotation runs after a verified backup');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://hc-ping.example.test/backup-uuid');
    }

    public function test_failed_off_site_copy_alerts_keeps_the_local_archive_and_skips_the_heartbeat(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'Failed to copy: 403 Forbidden', exitCode: 7)]);
        Http::fake();
        config([
            'darak.backup_rclone_remote' => 'offsite:darak-backups/prod',
            'darak.backup_heartbeat_url' => 'https://hc-ping.example.test/backup-uuid',
        ]);

        $this->artisan('darak:backup')->assertFailed();

        $this->assertCount(1, File::glob($this->root.'/darak-backup-*.zip'));
        $status = Cache::get(OffsiteBackupService::STATUS_CACHE_KEY);
        $this->assertFalse($status['ok']);
        $this->assertSame(7, $status['exit_code']);
        $alert = NotificationMessage::where('type', OpsAlertService::TYPE)->where('user_id', $this->owner->id)->first();
        $this->assertNotNull($alert, 'the owner inbox receives the failure');
        $this->assertStringContainsString('403 Forbidden', $alert->body);
        Http::assertNothingSent();

        $this->artisan('darak:health')->expectsOutputToContain('last copy failed')->assertFailed();
    }

    public function test_a_backup_that_cannot_be_created_alerts_the_owner(): void
    {
        config(['darak.pg_dump_path' => $this->root.'/missing/pg_dump']);
        Log::spy();

        $this->artisan('darak:backup')->assertFailed();

        $this->assertDatabaseHas('notification_messages', [
            'type' => OpsAlertService::TYPE, 'user_id' => $this->owner->id, 'channel' => 'in_app',
        ]);
        Log::shouldHaveReceived('critical')->withArgs(fn (string $message): bool => str_contains($message, 'فشل النسخ الاحتياطي'));
    }

    public function test_owner_alerts_are_deduplicated_per_kind_and_day(): void
    {
        $alerts = app(OpsAlertService::class);

        $this->assertSame(1, $alerts->raise('backup_failed', 'x', 'first'));
        $this->assertSame(0, $alerts->raise('backup_failed', 'x', 'second'));
        $this->assertSame(1, $alerts->raise('backup_offsite_failed', 'y', 'other kind'));
    }

    public function test_scheduler_heartbeat_pings_at_most_every_five_minutes_and_never_fails(): void
    {
        Http::fake(['https://hc-ping.example.test/*' => Http::response('OK')]);
        config(['darak.heartbeat_url' => 'https://hc-ping.example.test/scheduler-uuid']);

        $this->artisan('darak:heartbeat')->assertSuccessful();
        $this->travel(1)->minutes();
        $this->artisan('darak:heartbeat')->assertSuccessful();
        Http::assertSentCount(1);

        $this->travel(5)->minutes();
        $this->artisan('darak:heartbeat')->assertSuccessful();
        Http::assertSentCount(2);

        Http::fake(['*' => Http::failedConnection('timeout')]);
        $this->travel(5)->minutes();
        $this->artisan('darak:heartbeat')->assertSuccessful();
    }

    public function test_health_reports_the_heartbeat_age(): void
    {
        File::put($this->root.'/darak-backup-test.zip', 'x');
        config(['darak.monitoring.minimum_free_disk_bytes' => 1]);
        $this->artisan('darak:heartbeat')->assertSuccessful();
        $this->travel(42)->seconds();

        $this->artisan('darak:health', ['--json' => true])
            ->expectsOutputToContain('"age_seconds":42')
            ->assertSuccessful();
    }

    public function test_alert_webhook_channel_posts_critical_messages_once(): void
    {
        Http::fake();
        config(['logging.channels.alert_webhook.url' => 'https://hooks.example.test/services/T/B/X']);
        Log::forgetChannel('alert_webhook');

        Log::channel('alert_webhook')->error('not critical enough');
        Log::channel('alert_webhook')->critical('Nightly backup failed');
        Log::channel('alert_webhook')->critical('Nightly backup failed');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://hooks.example.test/services/T/B/X'
            && str_contains($request['text'], 'CRITICAL: Nightly backup failed'));
    }

    public function test_alert_webhook_joins_the_stack_only_when_configured(): void
    {
        $this->assertNotContains('alert_webhook', $this->loggingConfig(null)['channels']['stack']['channels']);
        $this->assertContains('alert_webhook', $this->loggingConfig('https://hooks.example.test/x')['channels']['stack']['channels']);
    }

    /** @return array<string, mixed> */
    private function loggingConfig(?string $url): array
    {
        $previous = [$_ENV['LOG_ALERT_WEBHOOK_URL'] ?? null, $_SERVER['LOG_ALERT_WEBHOOK_URL'] ?? null];

        try {
            if ($url === null) {
                unset($_ENV['LOG_ALERT_WEBHOOK_URL'], $_SERVER['LOG_ALERT_WEBHOOK_URL']);
                putenv('LOG_ALERT_WEBHOOK_URL');
            } else {
                $_ENV['LOG_ALERT_WEBHOOK_URL'] = $_SERVER['LOG_ALERT_WEBHOOK_URL'] = $url;
                putenv('LOG_ALERT_WEBHOOK_URL='.$url);
            }

            return require config_path('logging.php');
        } finally {
            unset($_ENV['LOG_ALERT_WEBHOOK_URL'], $_SERVER['LOG_ALERT_WEBHOOK_URL']);
            putenv('LOG_ALERT_WEBHOOK_URL');
            if ($previous[0] !== null) {
                $_ENV['LOG_ALERT_WEBHOOK_URL'] = $previous[0];
            }
            if ($previous[1] !== null) {
                $_SERVER['LOG_ALERT_WEBHOOK_URL'] = $previous[1];
            }
        }
    }
}
