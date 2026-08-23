<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PreflightCommand extends Command
{
    protected $signature = 'darak:preflight';

    protected $description = 'Fail when production security and operational settings are unsafe.';

    public function handle(): int
    {
        $backupPath = (string) config('darak.backup_path');
        $backupPassword = (string) config('darak.backup_password');
        $expiration = (int) config('sanctum.expiration');
        $privacy = (array) config('darak.privacy', []);
        $retention = (array) ($privacy['retention_days'] ?? []);
        $requiredPrivacyText = [
            'controller_name', 'request_channel', 'providers_register',
            'request_procedure', 'notice_version', 'emergency_consent_version',
        ];
        $privacyTextApproved = collect($requiredPrivacyText)
            ->every(fn (string $key): bool => is_string($privacy[$key] ?? null) && trim($privacy[$key]) !== '');
        $requiredRetention = [
            'visit_photos', 'emergency_reports', 'signatures_reports',
            'capture_coordinates', 'audit_security_logs', 'backups',
        ];
        $retentionApproved = collect($requiredRetention)
            ->every(fn (string $key): bool => filter_var($retention[$key] ?? null, FILTER_VALIDATE_INT) !== false
                && (int) $retention[$key] > 0);

        $checks = [
            ['Production environment', config('app.env') === 'production', (string) config('app.env')],
            ['Debug disabled', config('app.debug') === false, config('app.debug') ? 'enabled' : 'disabled'],
            ['HTTPS application URL', str_starts_with((string) config('app.url'), 'https://'), (string) config('app.url')],
            ['Application key present', strlen((string) config('app.key')) >= 32, 'configured'],
            ['Secure session cookie', config('session.secure') === true, config('session.secure') ? 'enabled' : 'disabled'],
            ['Finite device-token lifetime', $expiration > 0 && $expiration <= 43200, $expiration.' minutes'],
            ['PostgreSQL selected', config('database.default') === 'pgsql', (string) config('database.default')],
            ['Encrypted backups', strlen($backupPassword) >= 20, strlen($backupPassword) >= 20 ? 'configured' : 'missing'],
            ['External backup path', $this->isExternalBackupPath($backupPath), $backupPath === '' ? 'missing' : $backupPath],
            ['Persistent production runtime', ! (bool) config('darak.ephemeral_serverless'), config('darak.ephemeral_serverless') ? 'ephemeral/serverless' : 'persistent'],
            ['Privacy decisions approved', $privacyTextApproved, $privacyTextApproved ? 'configured' : 'missing required values'],
            ['Retention periods approved', $retentionApproved, $retentionApproved ? 'configured' : 'missing required positive day values'],
        ];

        $rows = [];
        $failed = false;

        foreach ($checks as [$name, $ok, $value]) {
            $rows[] = [$ok ? 'PASS' : 'FAIL', $name, $value];
            $failed = $failed || ! $ok;
        }

        $this->table(['Status', 'Check', 'Value'], $rows);

        if ($failed) {
            $this->error('Production preflight failed. Correct every FAIL before deployment.');

            return self::FAILURE;
        }

        $this->info('Production preflight passed.');

        return self::SUCCESS;
    }

    private function isExternalBackupPath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        $normalise = static fn (string $value): string => strtolower(str_replace('\\', '/', rtrim($value, '/\\')));
        $candidate = $normalise($path);
        $project = $normalise(base_path());

        return $candidate !== $project && ! str_starts_with($candidate.'/', $project.'/');
    }
}
