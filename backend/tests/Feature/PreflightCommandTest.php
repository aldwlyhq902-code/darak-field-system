<?php

namespace Tests\Feature;

use App\Services\PreflightProbe;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DarakTestCase;

class PreflightCommandTest extends DarakTestCase
{
    /** @var array<string, mixed> */
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->mutatedConfigKeys() as $key) {
            $this->originalConfig[$key] = config($key);
        }
    }

    protected function tearDown(): void
    {
        config($this->originalConfig);
        parent::tearDown();
    }

    public function test_safe_production_configuration_passes_preflight(): void
    {
        $this->safeProductionConfiguration();

        $this->artisan('darak:preflight')
            ->doesntExpectOutputToContain('FAIL')
            ->doesntExpectOutputToContain('WARN')
            ->expectsOutputToContain('Production preflight passed')
            ->assertSuccessful();
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function unsafeOperationalSettings(): array
    {
        return [
            'fake invoice provider' => [['darak.invoice_provider' => 'fake'], 'Real invoice provider'],
            'missing invoice provider' => [['darak.invoice_provider' => ''], 'Real invoice provider'],
            'log mailer' => [['mail.default' => 'log'], 'Delivering mailer'],
            'array mailer' => [['mail.default' => 'array'], 'Delivering mailer'],
            'local evidence without off-site copy' => [['darak.backup_rclone_remote' => null], 'Off-site copy of local evidence'],
            'missing audit HMAC key' => [['darak.audit.hmac_key' => null], 'Audit HMAC key configured'],
            'short audit HMAC key' => [['darak.audit.hmac_key' => 'too-short'], 'Audit HMAC key configured'],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('unsafeOperationalSettings')]
    public function test_unsafe_operational_settings_fail_preflight(array $override, string $check): void
    {
        $this->safeProductionConfiguration();
        config($override);

        $this->artisan('darak:preflight')
            ->expectsOutputToContain($check)
            ->expectsOutputToContain('Production preflight failed')
            ->assertFailed();
    }

    public function test_remote_evidence_disk_does_not_need_an_off_site_backup_remote(): void
    {
        $this->safeProductionConfiguration();
        config([
            'darak.backup_rclone_remote' => null,
            'darak.files_disk' => 'evidence',
            'filesystems.disks.evidence' => ['driver' => 'sftp', 'host' => 'files.example.test'],
        ]);

        $this->artisan('darak:preflight')->assertSuccessful();
    }

    public function test_s3_files_disk_without_its_adapter_fails_preflight(): void
    {
        $this->safeProductionConfiguration();
        config([
            'darak.files_disk' => 'evidence',
            'filesystems.disks.evidence' => ['driver' => 's3', 'bucket' => 'darak-evidence'],
        ]);

        // league/flysystem-aws-s3-v3 is deliberately not installed in this project.
        $this->assertFalse(class_exists(AwsS3V3Adapter::class));
        $this->artisan('darak:preflight')
            ->expectsOutputToContain('Files disk usable')
            ->expectsOutputToContain('Production preflight failed')
            ->assertFailed();
    }

    public function test_undefined_files_disk_fails_preflight(): void
    {
        $this->safeProductionConfiguration();
        config(['darak.files_disk' => 'missing-disk']);

        $this->artisan('darak:preflight')
            ->expectsOutputToContain('Files disk usable')
            ->assertFailed();
    }

    public function test_unknown_invoice_provider_fails_preflight(): void
    {
        $this->safeProductionConfiguration();
        config(['darak.invoice_provider' => 'zatca-typo']);

        $this->artisan('darak:preflight')
            ->expectsOutputToContain('Real invoice provider')
            ->assertFailed();
    }

    public function test_missing_pg_dump_fails_preflight(): void
    {
        $this->safeProductionConfiguration();
        $this->probe(missing: ['pg_dump', 'pg_dump.exe']);

        $this->artisan('darak:preflight')->expectsOutputToContain('pg_dump available')->assertFailed();
    }

    public function test_configured_remote_without_rclone_fails_preflight(): void
    {
        $this->safeProductionConfiguration();
        $this->probe(missing: ['rclone']);

        $this->artisan('darak:preflight')->expectsOutputToContain('rclone available')->assertFailed();
    }

    public function test_superuser_database_role_fails_preflight(): void
    {
        $this->safeProductionConfiguration();
        $this->probe(superuser: true);

        $this->artisan('darak:preflight')->expectsOutputToContain('superuser')->assertFailed();
    }

    public function test_unverifiable_database_role_fails_preflight(): void
    {
        $this->safeProductionConfiguration();
        $this->probe(superuser: null);

        $this->artisan('darak:preflight')->expectsOutputToContain('cannot verify')->assertFailed();
    }

    public function test_wrong_timezone_and_stale_heartbeat_only_warn(): void
    {
        $this->safeProductionConfiguration();
        config(['app.timezone' => 'UTC']);
        $this->probe(heartbeatAge: null);

        $this->artisan('darak:preflight')
            ->expectsOutputToContain('Saudi time zone')
            ->expectsOutputToContain('no heartbeat yet')
            ->expectsOutputToContain('Review every WARN')
            ->expectsOutputToContain('Production preflight passed')
            ->assertSuccessful();

        $this->probe(heartbeatAge: 900);
        $this->artisan('darak:preflight')->expectsOutputToContain('900 seconds old')->assertSuccessful();
    }

    public function test_real_probe_detects_the_test_database_superuser(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL role check.');
        }

        // The CI and local test databases connect as the postgres superuser:
        // exactly what production preflight must refuse.
        $this->assertTrue((new PreflightProbe)->databaseRoleIsSuperuser());
        $this->assertFalse((new PreflightProbe)->binaryAvailable('/definitely/missing/rclone'));
    }

    public function test_debug_mode_fails_preflight(): void
    {
        $this->safeProductionConfiguration();
        config(['app.debug' => true]);

        $this->artisan('darak:preflight')
            ->expectsOutputToContain('Production preflight failed')
            ->assertFailed();
    }

    public function test_backup_inside_the_deployment_fails_preflight(): void
    {
        $this->safeProductionConfiguration();
        config(['darak.backup_path' => storage_path('app/backups')]);

        $this->artisan('darak:preflight')->assertFailed();
    }

    public function test_missing_retention_approval_fails_preflight(): void
    {
        $this->safeProductionConfiguration();
        config(['darak.privacy.retention_days.visit_photos' => null]);

        $this->artisan('darak:preflight')
            ->expectsOutputToContain('Retention periods approved')
            ->assertFailed();
    }

    public function test_ephemeral_serverless_runtime_fails_preflight(): void
    {
        $this->safeProductionConfiguration();
        config(['darak.ephemeral_serverless' => true]);

        $this->artisan('darak:preflight')
            ->expectsOutputToContain('Persistent production runtime')
            ->assertFailed();
    }

    private function safeProductionConfiguration(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://panel.darak.sa',
            'app.key' => 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
            'session.secure' => true,
            'session.encrypt' => true,
            'sanctum.expiration' => 43200,
            'database.default' => 'pgsql',
            'database.connections.pgsql.sslmode' => 'require',
            'logging.default' => 'daily',
            'logging.channels.daily.level' => 'warning',
            'darak.backup_password' => 'a-very-long-production-backup-password',
            'darak.backup_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'darak-offsite-backups',
            'darak.ephemeral_serverless' => false,
            'darak.invoice_provider' => 'manual',
            'mail.default' => 'smtp',
            'darak.files_disk' => 'local',
            'darak.backup_rclone_remote' => 'offsite:darak-backups/prod',
            'darak.audit.hmac_key' => str_repeat('k', 64),
            'app.timezone' => 'Asia/Riyadh',
            'darak.privacy' => [
                'controller_name' => 'Approved controller',
                'request_channel' => 'privacy@example.test',
                'providers_register' => 'approved-register-v1',
                'request_procedure' => 'approved-procedure-v1',
                'notice_version' => 'approved-notice-v1',
                'emergency_consent_version' => 'approved-consent-v1',
                'retention_days' => [
                    'visit_photos' => 1,
                    'emergency_reports' => 1,
                    'signatures_reports' => 1,
                    'capture_coordinates' => 1,
                    'audit_security_logs' => 1,
                    'backups' => 1,
                ],
            ],
        ]);
        $this->probe();
    }

    /** @param array<int, string> $missing */
    private function probe(array $missing = [], ?bool $superuser = false, ?int $heartbeatAge = 30): void
    {
        $this->app->instance(PreflightProbe::class, new class($missing, $superuser, $heartbeatAge) extends PreflightProbe
        {
            /** @param array<int, string> $missing */
            public function __construct(private array $missing, private ?bool $superuser, private ?int $heartbeatAge) {}

            public function binaryAvailable(string $binary): bool
            {
                return ! in_array(basename(str_replace('\\', '/', $binary)), $this->missing, true);
            }

            public function databaseRoleIsSuperuser(): ?bool
            {
                return $this->superuser;
            }

            public function schedulerHeartbeatAge(): ?int
            {
                return $this->heartbeatAge;
            }
        });
    }

    public function test_debug_logging_fails_preflight(): void
    {
        $this->safeProductionConfiguration();
        config(['logging.channels.daily.level' => 'debug']);

        $this->artisan('darak:preflight')
            ->expectsOutputToContain('Production log level')
            ->assertFailed();
    }

    /** @return array<int, string> */
    private function mutatedConfigKeys(): array
    {
        return [
            'app.env', 'app.debug', 'app.url', 'app.key',
            'session.secure', 'session.encrypt', 'sanctum.expiration',
            'database.default', 'database.connections.pgsql.sslmode',
            'logging.default', 'logging.channels.daily.level',
            'darak.backup_password', 'darak.backup_path',
            'darak.ephemeral_serverless', 'darak.privacy',
            'darak.invoice_provider', 'mail.default', 'darak.files_disk', 'darak.backup_rclone_remote',
            'darak.audit.hmac_key', 'app.timezone',
        ];
    }
}
