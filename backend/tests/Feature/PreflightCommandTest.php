<?php

namespace Tests\Feature;

use Tests\DarakTestCase;

class PreflightCommandTest extends DarakTestCase
{
    public function test_safe_production_configuration_passes_preflight(): void
    {
        $this->safeProductionConfiguration();

        $this->artisan('darak:preflight')
            ->expectsOutputToContain('Production preflight passed')
            ->assertSuccessful();
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
            'darak.backup_password' => 'a-very-long-production-backup-password',
            'darak.backup_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'darak-offsite-backups',
            'darak.ephemeral_serverless' => false,
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
    }
}
