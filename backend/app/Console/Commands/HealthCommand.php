<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class HealthCommand extends Command
{
    protected $signature = 'darak:health {--json : Emit machine-readable JSON}';

    protected $description = 'Check database, scheduler, storage, disk space and backup freshness.';

    public function handle(): int
    {
        $checks = [
            'database' => $this->databaseHealthy(),
            'scheduler' => $this->schedulerHealthy(),
            'storage' => $this->storageHealthy(),
            'disk' => $this->diskHealthy(),
            'backup' => $this->backupHealthy(),
        ];
        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $healthy,
                'checked_at' => now()->toIso8601String(),
                'checks' => $checks,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(
                ['Status', 'Check', 'Detail'],
                collect($checks)->map(fn (array $check, string $name): array => [
                    $check['ok'] ? 'PASS' : 'FAIL', $name, $check['detail'],
                ])->values()->all(),
            );
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{ok: bool, detail: string} */
    private function databaseHealthy(): array
    {
        try {
            DB::select('select 1');

            return ['ok' => true, 'detail' => 'reachable'];
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => 'unreachable: '.$exception->getMessage()];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private function schedulerHealthy(): array
    {
        try {
            $timestamp = Cache::get('darak:monitor:scheduler-heartbeat');
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => 'cache unavailable: '.$exception->getMessage()];
        }

        $age = is_numeric($timestamp) ? now()->timestamp - (int) $timestamp : null;
        $maximum = (int) config('darak.monitoring.scheduler_max_age_seconds', 300);

        return [
            'ok' => $age !== null && $age >= 0 && $age <= $maximum,
            'detail' => $age === null ? 'heartbeat missing' : $age.' seconds old',
        ];
    }

    /** @return array{ok: bool, detail: string} */
    private function storageHealthy(): array
    {
        $paths = [storage_path('app/private'), storage_path('logs'), base_path('bootstrap/cache')];

        foreach ($paths as $path) {
            try {
                File::ensureDirectoryExists($path);
            } catch (Throwable $exception) {
                return ['ok' => false, 'detail' => $path.' cannot be created: '.$exception->getMessage()];
            }

            if (! is_writable($path)) {
                return ['ok' => false, 'detail' => $path.' is not writable'];
            }
        }

        return ['ok' => true, 'detail' => 'runtime paths writable'];
    }

    /** @return array{ok: bool, detail: string} */
    private function diskHealthy(): array
    {
        $free = disk_free_space(storage_path());
        $minimum = (int) config('darak.monitoring.minimum_free_disk_bytes', 1073741824);

        if ($free === false) {
            return ['ok' => false, 'detail' => 'free space unavailable'];
        }

        return [
            'ok' => $free >= $minimum,
            'detail' => round($free / 1048576).' MiB free',
        ];
    }

    /** @return array{ok: bool, detail: string} */
    private function backupHealthy(): array
    {
        $root = (string) (config('darak.backup_path') ?: storage_path('app/backups'));
        $archives = File::glob($root.DIRECTORY_SEPARATOR.'darak-backup-*.zip');

        if ($archives === []) {
            return ['ok' => false, 'detail' => 'no backup archive found'];
        }

        $latest = max(array_map(static fn (string $path): int => (int) filemtime($path), $archives));
        $age = now()->timestamp - $latest;
        $maximum = ((int) config('darak.rpo_hours', 24) + (int) config('darak.monitoring.backup_grace_hours', 2)) * 3600;

        return [
            'ok' => $age >= 0 && $age <= $maximum,
            'detail' => round($age / 3600, 1).' hours old',
        ];
    }
}
