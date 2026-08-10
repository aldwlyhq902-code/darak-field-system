<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/**
 * Backup and restore, with verification.
 *
 * Acceptance criterion 8 is not "a backup file exists" — it is that a restore on a
 * clean environment reproduces the data, proven by record counts and file hashes.
 * A backup nobody has ever restored is a guess, and the day you need it is the
 * worst possible time to discover it was wrong.
 *
 * RPO 24h / RTO 4h for the pilot (config/darak.php).
 */
class BackupService
{
    /** Tables whose row counts are recorded in the manifest and checked on restore. */
    private const VERIFIED_TABLES = [
        'users', 'clients', 'sites', 'assets', 'contracts', 'work_orders',
        'visits', 'visit_events', 'checklist_instances', 'media_files',
        'parts', 'stock_moves', 'subcontractors', 'subcontractor_orders',
        'external_documents', 'audit_logs', 'notification_messages',
    ];

    public function __construct(private readonly string $backupRoot)
    {
    }

    /**
     * @return array{path: string, manifest: array<string, mixed>}
     */
    public function create(?string $label = null): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required for backups.');
        }

        File::ensureDirectoryExists($this->backupRoot);

        $stamp = now()->format('Ymd-His');
        $name = 'darak-backup-' . $stamp . ($label ? '-' . $label : '') . '.zip';
        $target = $this->backupRoot . DIRECTORY_SEPARATOR . $name;

        $manifest = [
            'created_at' => now()->toIso8601String(),
            'app_version' => config('app.version', 'mvp'),
            'connection' => config('database.default'),
            'counts' => $this->counts(),
            'files' => [],
        ];

        $zip = new ZipArchive();

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create archive at {$target}.");
        }

        // Database. SQLite is a file copy; PostgreSQL is dumped as SQL so the
        // archive stays restorable without the original server.
        $connection = config('database.default');

        if ($connection === 'sqlite') {
            $database = config('database.connections.sqlite.database');

            if ($database !== ':memory:' && File::exists($database)) {
                // VACUUM INTO, not a raw file copy: copying the live file while a
                // write or a WAL checkpoint is in flight can capture a torn
                // database that only reveals itself on the day it is needed.
                $snapshot = $this->backupRoot . DIRECTORY_SEPARATOR . 'snapshot-' . uniqid() . '.sqlite';

                try {
                    DB::statement('VACUUM INTO ?', [$snapshot]);
                    $source = $snapshot;
                } catch (\Throwable) {
                    $source = $database; // very old SQLite: fall back to the copy
                }

                $zip->addFile($source, 'database/database.sqlite');
                $manifest['database'] = [
                    'driver' => 'sqlite',
                    'entry' => 'database/database.sqlite',
                    'sha256' => hash_file('sha256', $source),
                ];
            } else {
                $manifest['database'] = ['driver' => 'sqlite', 'entry' => null, 'note' => 'in-memory database not archivable'];
            }
        } else {
            // Previously this only DECLARED an entry that nothing ever added, and
            // verify() did not check. Every nightly run reported success while the
            // archive held zero rows — a silently infinite RPO with green ticks.
            $dump = $this->dumpDatabase($connection);

            if ($dump === null) {
                $zip->close();
                @unlink($target);

                throw new RuntimeException(
                    'Database dump failed (is pg_dump on PATH?). Refusing to write a backup with no database in it.'
                );
            }

            $zip->addFromString('database/dump.sql', $dump);

            $manifest['database'] = [
                'driver' => $connection,
                'entry' => 'database/dump.sql',
                'sha256' => hash('sha256', $dump),
                'bytes' => strlen($dump),
            ];
        }

        // Evidence files, hashed so a silently corrupted restore is detectable.
        $storageRoot = storage_path('app/private');
        $storageRoot = File::isDirectory($storageRoot) ? $storageRoot : storage_path('app');

        foreach ($this->evidenceFiles($storageRoot) as $absolute) {
            $relative = 'storage/' . str_replace('\\', '/', substr($absolute, strlen($storageRoot) + 1));
            $zip->addFile($absolute, $relative);
            $manifest['files'][$relative] = hash_file('sha256', $absolute);
        }

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->close();

        // The VACUUM snapshot exists only to be archived.
        foreach (File::glob($this->backupRoot . DIRECTORY_SEPARATOR . 'snapshot-*.sqlite') as $stale) {
            @unlink($stale);
        }

        return ['path' => $target, 'manifest' => $manifest];
    }

    /**
     * Verifies an archive against the live database without touching anything.
     *
     * @return array{ok: bool, mismatched_counts: array<string, array{expected:int, actual:int}>, corrupt_files: array<int, string>, missing_files: array<int, string>}
     */
    public function verify(string $archivePath): array
    {
        $manifest = $this->readManifest($archivePath);

        $mismatched = [];

        foreach ($manifest['counts'] ?? [] as $table => $expected) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $actual = DB::table($table)->count();

            if ($actual !== $expected) {
                $mismatched[$table] = ['expected' => $expected, 'actual' => $actual];
            }
        }

        $zip = new ZipArchive();
        $zip->open($archivePath);

        $corrupt = [];
        $missing = [];

        // A declared database that is not actually in the archive is the whole
        // failure mode this method exists to catch.
        $declared = $manifest['database']['entry'] ?? null;

        if ($declared !== null) {
            $contents = $zip->getFromName($declared);

            if ($contents === false) {
                $missing[] = $declared;
            } elseif (isset($manifest['database']['sha256'])
                && hash('sha256', $contents) !== $manifest['database']['sha256']) {
                $corrupt[] = $declared;
            }
        }

        foreach ($manifest['files'] ?? [] as $relative => $expectedHash) {
            $contents = $zip->getFromName($relative);

            if ($contents === false) {
                $missing[] = $relative;
                continue;
            }

            if (hash('sha256', $contents) !== $expectedHash) {
                $corrupt[] = $relative;
            }
        }

        $zip->close();

        return [
            'ok' => $mismatched === [] && $corrupt === [] && $missing === [],
            'mismatched_counts' => $mismatched,
            'corrupt_files' => $corrupt,
            'missing_files' => $missing,
        ];
    }

    /**
     * Restores into the CURRENT environment. Intended for a clean target — the
     * drill is to restore onto an empty box and compare, never onto production.
     *
     * @return array{restored_files: int, database_restored: bool, manifest: array<string, mixed>}
     */
    public function restore(string $archivePath, ?string $storageTarget = null): array
    {
        $manifest = $this->readManifest($archivePath);

        $zip = new ZipArchive();

        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException("Cannot open archive {$archivePath}.");
        }

        $storageTarget ??= storage_path('app/private');
        File::ensureDirectoryExists($storageTarget);

        $restoredFiles = 0;

        foreach ($manifest['files'] ?? [] as $relative => $expectedHash) {
            $contents = $zip->getFromName($relative);

            if ($contents === false) {
                continue;
            }

            if (hash('sha256', $contents) !== $expectedHash) {
                throw new RuntimeException("Hash mismatch restoring {$relative} — archive is corrupt.");
            }

            $destination = $storageTarget . DIRECTORY_SEPARATOR . substr($relative, strlen('storage/'));
            File::ensureDirectoryExists(dirname($destination));
            File::put($destination, $contents);
            $restoredFiles++;
        }

        $databaseRestored = false;
        $entry = $manifest['database']['entry'] ?? null;
        $driver = $manifest['database']['driver'] ?? null;

        if ($entry !== null) {
            $contents = $zip->getFromName($entry);

            if ($contents === false) {
                $zip->close();

                throw new RuntimeException(
                    "Archive declares a database at [{$entry}] but does not contain it — refusing to report a successful restore."
                );
            }

            if ($driver === 'sqlite') {
                $target = config('database.connections.sqlite.database');

                if ($target !== ':memory:') {
                    File::put($target, $contents);
                    $databaseRestored = true;
                }
            } else {
                // The SQL dump is written next to the archive for psql to apply;
                // restoring a live PostgreSQL from inside the app would be worse
                // than useless. DEPLOYMENT.md carries the one-line command.
                $sqlPath = dirname($archivePath) . DIRECTORY_SEPARATOR
                    . pathinfo($archivePath, PATHINFO_FILENAME) . '.sql';
                File::put($sqlPath, $contents);
            }
        }

        $zip->close();

        return [
            'restored_files' => $restoredFiles,
            'database_restored' => $databaseRestored,
            'manifest' => $manifest,
        ];
    }

    /**
     * Shells out to pg_dump. Returns null when the dump cannot be produced —
     * the caller then refuses to write an archive at all, because a backup that
     * silently contains no database is worse than no backup: it is a backup you
     * believe in.
     */
    private function dumpDatabase(string $connection): ?string
    {
        $config = config("database.connections.{$connection}");

        if (($config['driver'] ?? null) !== 'pgsql') {
            return null;
        }

        $target = tempnam(sys_get_temp_dir(), 'darak-dump-');

        $command = sprintf(
            'pg_dump --no-owner --no-privileges --format=plain --host=%s --port=%s --username=%s --dbname=%s --file=%s',
            escapeshellarg((string) ($config['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($config['port'] ?? 5432)),
            escapeshellarg((string) ($config['username'] ?? '')),
            escapeshellarg((string) ($config['database'] ?? '')),
            escapeshellarg($target),
        );

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = ['PGPASSWORD' => (string) ($config['password'] ?? '')] + $_ENV;

        $process = @proc_open($command, $descriptors, $pipes, null, $env);

        if (! is_resource($process)) {
            @unlink($target);

            return null;
        }

        foreach ($pipes as $pipe) {
            stream_get_contents($pipe);
            fclose($pipe);
        }

        $exitCode = proc_close($process);
        $dump = ($exitCode === 0 && is_file($target)) ? file_get_contents($target) : null;

        @unlink($target);

        return ($dump === false || $dump === '' || $dump === null) ? null : $dump;
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];

        foreach (self::VERIFIED_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }

    /** @return array<int, string> */
    private function evidenceFiles(string $root): array
    {
        if (! File::isDirectory($root)) {
            return [];
        }

        $files = [];

        foreach (File::allFiles($root) as $file) {
            // Backups of backups serve nobody and double the archive each run.
            if (str_contains(str_replace('\\', '/', $file->getPathname()), '/backups/')) {
                continue;
            }

            $files[] = $file->getPathname();
        }

        return $files;
    }

    /** @return array<string, mixed> */
    private function readManifest(string $archivePath): array
    {
        if (! File::exists($archivePath)) {
            throw new RuntimeException("Archive not found: {$archivePath}");
        }

        $zip = new ZipArchive();

        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException("Cannot open archive {$archivePath}.");
        }

        $raw = $zip->getFromName('manifest.json');
        $zip->close();

        if ($raw === false) {
            throw new RuntimeException('Archive has no manifest — it was not produced by darak:backup.');
        }

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
