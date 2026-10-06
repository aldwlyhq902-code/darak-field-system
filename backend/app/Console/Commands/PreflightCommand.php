<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\BackupService;
use App\Services\OffsiteBackupService;
use App\Services\PreflightProbe;
use Illuminate\Console\Command;

class PreflightCommand extends Command
{
    protected $signature = 'darak:preflight';

    protected $description = 'Fail when production security and operational settings are unsafe.';

    public function handle(PreflightProbe $probe, BackupService $backups, OffsiteBackupService $offsite): int
    {
        $backupPath = (string) config('darak.backup_path');
        $backupPassword = (string) config('darak.backup_password');
        $expiration = (int) config('sanctum.expiration');
        $databaseSslMode = (string) config('database.connections.pgsql.sslmode');
        $logChannel = (string) config('logging.default');
        $logLevel = $this->effectiveLogLevel($logChannel);
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

        $invoiceProvider = strtolower(trim((string) config('darak.invoice_provider')));
        $mailer = strtolower(trim((string) config('mail.default')));
        $filesDisk = (string) config('darak.files_disk', 'local');
        $filesOnLocalDisk = config("filesystems.disks.{$filesDisk}.driver", 'local') === 'local';
        $remote = $offsite->remote();
        $pgDump = $backups->pgDumpBinary();
        $superuser = $probe->databaseRoleIsSuperuser();
        $timezone = (string) config('app.timezone');
        $auditKey = (string) AuditLogger::hmacKey();
        $heartbeatAge = $probe->schedulerHeartbeatAge();
        $heartbeatLimit = 300;

        // [name, ok, value, warn-only]
        $checks = [
            ['Production environment', config('app.env') === 'production', (string) config('app.env')],
            ['Debug disabled', config('app.debug') === false, config('app.debug') ? 'enabled' : 'disabled'],
            ['HTTPS application URL', str_starts_with((string) config('app.url'), 'https://'), (string) config('app.url')],
            ['Application key present', strlen((string) config('app.key')) >= 32, 'configured'],
            ['Secure session cookie', config('session.secure') === true, config('session.secure') ? 'enabled' : 'disabled'],
            ['Encrypted session payload', config('session.encrypt') === true, config('session.encrypt') ? 'enabled' : 'disabled'],
            ['Finite device-token lifetime', $expiration > 0 && $expiration <= 43200, $expiration.' minutes'],
            ['PostgreSQL selected', config('database.default') === 'pgsql', (string) config('database.default')],
            ['Encrypted PostgreSQL transport', in_array($databaseSslMode, ['require', 'verify-ca', 'verify-full'], true), $databaseSslMode],
            ['Operational logging enabled', ! in_array($logChannel, ['', 'null'], true), $logChannel ?: 'missing'],
            ['Production log level', ! in_array($logLevel, ['', 'debug'], true), $logLevel ?: 'missing'],
            ['Encrypted backups', strlen($backupPassword) >= 20, strlen($backupPassword) >= 20 ? 'configured' : 'missing'],
            ['External backup path', $this->isExternalBackupPath($backupPath), $backupPath === '' ? 'missing' : $backupPath],
            ['Persistent production runtime', ! (bool) config('darak.ephemeral_serverless'), config('darak.ephemeral_serverless') ? 'ephemeral/serverless' : 'persistent'],
            ['Privacy decisions approved', $privacyTextApproved, $privacyTextApproved ? 'configured' : 'missing required values'],
            ['Retention periods approved', $retentionApproved, $retentionApproved ? 'configured' : 'missing required positive day values'],
            // A fake provider issues nothing to ZATCA while the panel shows invoices as issued.
            ['Real invoice provider', ! in_array($invoiceProvider, ['', 'fake'], true), $invoiceProvider ?: 'missing'],
            // Password reset and client notices need a mailer that actually delivers.
            ['Delivering mailer', ! in_array($mailer, ['', 'log', 'array', 'null'], true), $mailer ?: 'missing'],
            ['Off-site copy of local evidence', ! $filesOnLocalDisk || $remote !== null, $filesOnLocalDisk ? ($remote ?? 'local disk, no DARAK_BACKUP_RCLONE_REMOTE') : $filesDisk.' disk'],
            ['pg_dump available', $probe->binaryAvailable($pgDump), $pgDump],
            ['rclone available for off-site copy', $remote === null || $probe->binaryAvailable($offsite->binary()), $remote === null ? 'not configured' : $offsite->binary()],
            ['Database role is not a superuser', $superuser === false, match ($superuser) {
                true => 'superuser', false => 'least privilege', null => 'cannot verify',
            }],
            ['Audit HMAC key configured', strlen($auditKey) >= 32, $auditKey === '' ? 'missing' : (strlen($auditKey) >= 32 ? 'configured' : 'shorter than 32 characters')],
            ['Saudi time zone', $timezone === 'Asia/Riyadh', $timezone ?: 'missing', true],
            // May legitimately be absent on the very first deploy: cron starts after it.
            ['Scheduler heartbeat fresh', $heartbeatAge !== null && $heartbeatAge <= $heartbeatLimit, $heartbeatAge === null ? 'no heartbeat yet' : $heartbeatAge.' seconds old', true],
        ];

        $rows = [];
        $failed = false;
        $warned = false;

        foreach ($checks as $check) {
            [$name, $ok, $value] = $check;
            $warnOnly = $check[3] ?? false;
            $status = $ok ? 'PASS' : ($warnOnly ? 'WARN' : 'FAIL');
            $rows[] = [$status, $name, $value];
            $failed = $failed || (! $ok && ! $warnOnly);
            $warned = $warned || (! $ok && $warnOnly);
        }

        $this->table(['Status', 'Check', 'Value'], $rows);

        if ($failed) {
            $this->error('Production preflight failed. Correct every FAIL before deployment.');

            return self::FAILURE;
        }

        if ($warned) {
            $this->warn('Review every WARN: it does not block deployment.');
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

    private function effectiveLogLevel(string $channel): string
    {
        if ($channel !== 'stack') {
            return strtolower((string) config("logging.channels.{$channel}.level", ''));
        }

        $levels = collect(config('logging.channels.stack.channels', []))
            ->map(fn (string $member): string => strtolower((string) config("logging.channels.{$member}.level", '')))
            ->filter();

        return $levels->contains('debug') ? 'debug' : (string) $levels->first();
    }
}
