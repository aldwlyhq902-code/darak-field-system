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

    private function safeProductionConfiguration(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://panel.darak.sa',
            'app.key' => 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
            'session.secure' => true,
            'sanctum.expiration' => 43200,
            'database.default' => 'pgsql',
            'darak.backup_password' => 'a-very-long-production-backup-password',
            'darak.backup_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'darak-offsite-backups',
        ]);
    }
}
