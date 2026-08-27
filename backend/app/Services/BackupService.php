<?php

namespace App\Services;

use Closure;
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

    public function __construct(
        private readonly string $backupRoot,
        private readonly ?string $password = null,
        private readonly bool $requireEncryption = false,
        private readonly ?Closure $dumpRunner = null,
    ) {}

    /**
     * @return array{path: string, manifest: array<string, mixed>}
     */
    public function create(?string $label = null): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required for backups.');
        }

        if ($this->requireEncryption && ! $this->hasPassword()) {
            throw new RuntimeException(
                'DARAK_BACKUP_PASSWORD is required in production; refusing to create an unencrypted backup.'
            );
        }

        File::ensureDirectoryExists($this->backupRoot);

        $stamp = now()->format('Ymd-His');
        $name = 'darak-backup-'.$stamp.($label ? '-'.$label : '').'.zip';
        $target = $this->backupRoot.DIRECTORY_SEPARATOR.$name;

        $manifest = [
            'created_at' => now()->toIso8601String(),
            'app_version' => config('app.version', 'mvp'),
            'connection' => config('database.default'),
            'encrypted' => $this->hasPassword(),
            'counts' => $this->counts(),
            'files' => [],
        ];

        $entries = [];
        $temporaryFiles = [];

        $zip = new ZipArchive;

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
                $snapshot = $this->backupRoot.DIRECTORY_SEPARATOR.'snapshot-'.uniqid().'.sqlite';

                try {
                    DB::statement('VACUUM INTO ?', [$snapshot]);
                    $source = $snapshot;
                } catch (\Throwable) {
                    $source = $database; // very old SQLite: fall back to the copy
                }

                $zip->addFile($source, 'database/database.sqlite');
                $entries[] = 'database/database.sqlite';
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

            if (! $zip->addFile($dump, 'database/dump.sql')) {
                @unlink($dump);
                $zip->close();
                @unlink($target);

                throw new RuntimeException('Could not stream the database dump into the backup archive.');
            }
            $temporaryFiles[] = $dump;
            $entries[] = 'database/dump.sql';

            $manifest['database'] = [
                'driver' => $connection,
                'entry' => 'database/dump.sql',
                'sha256' => hash_file('sha256', $dump),
                'bytes' => filesize($dump),
            ];
        }

        // Evidence files, hashed so a silently corrupted restore is detectable.
        $storageRoot = storage_path('app/private');
        $storageRoot = File::isDirectory($storageRoot) ? $storageRoot : storage_path('app');

        foreach ($this->evidenceFiles($storageRoot) as $absolute) {
            $relative = 'storage/'.str_replace('\\', '/', substr($absolute, strlen($storageRoot) + 1));
            $zip->addFile($absolute, $relative);
            $entries[] = $relative;
            $manifest['files'][$relative] = hash_file('sha256', $absolute);
        }

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $entries[] = 'manifest.json';

        if ($this->hasPassword()) {
            $this->encryptEntries($zip, $entries);
        }

        $zip->close();

        foreach ($temporaryFiles as $temporaryFile) {
            @unlink($temporaryFile);
        }

        // The VACUUM snapshot exists only to be archived.
        foreach (File::glob($this->backupRoot.DIRECTORY_SEPARATOR.'snapshot-*.sqlite') as $stale) {
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

        $zip = $this->openArchive($archivePath);

        $corrupt = [];
        $missing = [];

        // A declared database that is not actually in the archive is the whole
        // failure mode this method exists to catch.
        $declared = $manifest['database']['entry'] ?? null;

        if ($declared !== null) {
            $actualHash = $this->hashArchiveEntry($zip, $declared);

            if ($actualHash === null) {
                $missing[] = $declared;
            } elseif (isset($manifest['database']['sha256'])
                && $actualHash !== $manifest['database']['sha256']) {
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

        $zip = $this->openArchive($archivePath);

        $storageTarget ??= storage_path('app/private');
        File::ensureDirectoryExists($storageTarget);
        $storageRoot = realpath($storageTarget);

        if ($storageRoot === false) {
            throw new RuntimeException("Cannot resolve restore target [{$storageTarget}].");
        }

        $restoredFiles = 0;

        foreach ($manifest['files'] ?? [] as $relative => $expectedHash) {
            $storageRelative = $this->safeStorageRelativePath((string) $relative);
            $contents = $zip->getFromName($relative);

            if ($contents === false) {
                continue;
            }

            if (hash('sha256', $contents) !== $expectedHash) {
                throw new RuntimeException("Hash mismatch restoring {$relative} — archive is corrupt.");
            }

            $destination = $storageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $storageRelative);
            File::ensureDirectoryExists(dirname($destination));

            $destinationParent = realpath(dirname($destination));

            if ($destinationParent === false || ! $this->isWithinDirectory($destinationParent, $storageRoot)) {
                throw new RuntimeException("Unsafe restore destination for [{$relative}].");
            }

            File::put($destination, $contents);
            $restoredFiles++;
        }

        $databaseRestored = false;
        $entry = $manifest['database']['entry'] ?? null;
        $driver = $manifest['database']['driver'] ?? null;

        if ($entry !== null) {
            $stream = $zip->getStream($entry);

            if ($stream === false) {
                $zip->close();

                throw new RuntimeException(
                    "Archive declares a database at [{$entry}] but does not contain it — refusing to report a successful restore."
                );
            }

            if ($driver === 'sqlite') {
                $target = config('database.connections.sqlite.database');

                if ($target !== ':memory:') {
                    $this->copyStreamToPath($stream, $target);
                    $databaseRestored = true;
                }
            } else {
                // The SQL dump is written next to the archive for psql to apply;
                // restoring a live PostgreSQL from inside the app would be worse
                // than useless. DEPLOYMENT.md carries the one-line command.
                $sqlPath = dirname($archivePath).DIRECTORY_SEPARATOR
                    .pathinfo($archivePath, PATHINFO_FILENAME).'.sql';
                $this->copyStreamToPath($stream, $sqlPath);
            }

            fclose($stream);
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

        $command = [
            $this->pgDumpBinary(),
            '--no-owner',
            '--no-privileges',
            '--format=plain',
            '--host='.(string) ($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? 5432),
            '--username='.(string) ($config['username'] ?? ''),
            '--dbname='.(string) ($config['database'] ?? ''),
            '--file='.$target,
        ];

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $environment = getenv();
        $env = array_replace(is_array($environment) ? $environment : [], [
            'PGPASSWORD' => (string) ($config['password'] ?? ''),
        ]);

        if ($this->dumpRunner !== null) {
            $exitCode = ($this->dumpRunner)($command, $env, $target);

            if ($exitCode === 0 && is_file($target) && filesize($target) > 0) {
                return $target;
            }

            @unlink($target);

            return null;
        }

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

        if ($exitCode !== 0 || ! is_file($target) || filesize($target) === 0) {
            @unlink($target);

            return null;
        }

        return $target;
    }

    private function hashArchiveEntry(ZipArchive $zip, string $entry): ?string
    {
        $stream = $zip->getStream($entry);

        if ($stream === false) {
            return null;
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    /** @param resource $source */
    private function copyStreamToPath($source, string $target): void
    {
        File::ensureDirectoryExists(dirname($target));
        $destination = fopen($target, 'wb');

        if ($destination === false) {
            throw new RuntimeException("Cannot open restore target [{$target}].");
        }

        try {
            if (stream_copy_to_stream($source, $destination) === false) {
                throw new RuntimeException("Cannot restore database to [{$target}].");
            }
        } finally {
            fclose($destination);
        }
    }

    private function pgDumpBinary(): string
    {
        $configured = config('darak.pg_dump_path');

        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $programFiles = getenv('ProgramFiles');

            if (is_string($programFiles) && $programFiles !== '') {
                $candidates = glob($programFiles.DIRECTORY_SEPARATOR.'PostgreSQL'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'pg_dump.exe') ?: [];
                natsort($candidates);

                if ($candidate = end($candidates)) {
                    return $candidate;
                }
            }
        }

        return 'pg_dump';
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

        $zip = $this->openArchive($archivePath);

        $raw = $zip->getFromName('manifest.json');
        $zip->close();

        if ($raw === false) {
            throw new RuntimeException(
                'Cannot read the backup manifest. The archive is invalid or DARAK_BACKUP_PASSWORD is wrong.'
            );
        }

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    private function hasPassword(): bool
    {
        return is_string($this->password) && strlen($this->password) >= 20;
    }

    private function safeStorageRelativePath(string $entry): string
    {
        $entry = str_replace('\\', '/', $entry);

        if (! str_starts_with($entry, 'storage/') || str_contains($entry, "\0")) {
            throw new RuntimeException("Unsafe storage entry in backup manifest [{$entry}].");
        }

        $segments = explode('/', substr($entry, strlen('storage/')));

        if ($segments === [] || collect($segments)->contains(fn (string $segment): bool => $segment === '' || $segment === '.' || $segment === '..')) {
            throw new RuntimeException("Unsafe storage entry in backup manifest [{$entry}].");
        }

        return implode('/', $segments);
    }

    private function isWithinDirectory(string $candidate, string $root): bool
    {
        $normalise = static function (string $path): string {
            $path = str_replace('\\', '/', rtrim($path, '/\\'));

            return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
        };
        $candidate = $normalise($candidate);
        $root = $normalise($root);

        return $candidate === $root || str_starts_with($candidate.'/', $root.'/');
    }

    /** @param array<int, string> $entries */
    private function encryptEntries(ZipArchive $zip, array $entries): void
    {
        if (! $zip->setPassword((string) $this->password)) {
            throw new RuntimeException('Cannot set the backup encryption password.');
        }

        foreach ($entries as $entry) {
            if (! $zip->setEncryptionName($entry, ZipArchive::EM_AES_256)) {
                throw new RuntimeException("Cannot encrypt backup entry [{$entry}] with AES-256.");
            }
        }
    }

    private function openArchive(string $archivePath): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException("Cannot open archive {$archivePath}.");
        }

        if ($this->hasPassword()) {
            $zip->setPassword((string) $this->password);
        }

        return $zip;
    }
}
