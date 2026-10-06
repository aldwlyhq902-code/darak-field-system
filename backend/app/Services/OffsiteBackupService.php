<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Copies a finished archive off the server with rclone.
 *
 * A backup on the same disk as the database is not a backup: losing the disk
 * loses both. The remote is an rclone target such as `remote:bucket/darak`,
 * configured once on the server with `rclone config`.
 *
 * The command is an argument array, never a shell string, so a path or remote
 * name can never be interpreted by a shell. A failed copy is reported to the
 * caller and recorded; it never touches the local archive.
 */
class OffsiteBackupService
{
    public const STATUS_CACHE_KEY = 'darak:monitor:backup-offsite';

    public function remote(): ?string
    {
        $remote = config('darak.backup_rclone_remote');

        return is_string($remote) && trim($remote) !== '' ? trim($remote) : null;
    }

    public function configured(): bool
    {
        return $this->remote() !== null;
    }

    public function binary(): string
    {
        $binary = config('darak.rclone_path');

        return is_string($binary) && trim($binary) !== '' ? trim($binary) : 'rclone';
    }

    /** @return array<int, string> */
    public function command(string $archive): array
    {
        return [
            $this->binary(), 'copy', $archive, (string) $this->remote(),
            '--retries', '3', '--low-level-retries', '10', '--stats', '0',
        ];
    }

    /** @return array{ok: bool, exit_code: int|null, error: string|null} */
    public function copy(string $archive): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'exit_code' => null, 'error' => 'DARAK_BACKUP_RCLONE_REMOTE is not configured'];
        }

        try {
            $result = Process::timeout(max(60, (int) config('darak.rclone_timeout_seconds', 3600)))
                ->run($this->command($archive));
            $outcome = [
                'ok' => $result->successful(),
                'exit_code' => $result->exitCode(),
                'error' => $result->successful() ? null : $this->tail($result->errorOutput() ?: $result->output()),
            ];
        } catch (Throwable $exception) {
            $outcome = ['ok' => false, 'exit_code' => null, 'error' => $this->tail($exception->getMessage())];
        }

        $this->record(basename($archive), $outcome);

        return $outcome;
    }

    /** @param array{ok: bool, exit_code: int|null, error: string|null} $outcome */
    private function record(string $archive, array $outcome): void
    {
        try {
            Cache::put(self::STATUS_CACHE_KEY, $outcome + [
                'archive' => $archive,
                'at' => now()->timestamp,
            ], now()->addDays(14));
        } catch (Throwable) {
            // Status is informational; the caller already holds the outcome.
        }
    }

    private function tail(string $text): string
    {
        $text = trim($text);

        return mb_strlen($text) > 500 ? '…'.mb_substr($text, -500) : ($text === '' ? 'unknown error' : $text);
    }
}
