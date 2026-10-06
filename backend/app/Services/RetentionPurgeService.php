<?php

namespace App\Services;

use App\Models\EmergencyReport;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Applies the approved retention periods (config darak.privacy.retention_days).
 *
 * Expired evidence loses its BYTES, not its row: the media row keeps kind, hash
 * and timestamps (plus purged_at), so a visit history still shows that a photo
 * existed and what it hashed to. Coordinates are nulled in place.
 *
 * Holds — nothing tied to a visit is purged while any of these is true:
 *   - an open report dispute on the visit (report_disputes not resolved/rejected),
 *   - an unresolved complaint on the visit (visit_feedback.is_complaint),
 *   - an e-invoice for the visit or its work order still requested/failed,
 *   - an overdue, not fully paid installment on the visit's contract.
 * There is no "legal hold" flag in the schema yet; anything the owner must keep
 * for another reason (litigation, insurer request) needs that flag first, or
 * the purge must stay disabled.
 *
 * The audit log is never purged: it is an append-only hash chain and deleting
 * its head would make every later entry unverifiable. Personal data is kept
 * out of it at write time instead (AuditLogger redaction).
 */
class RetentionPurgeService
{
    public const CATEGORIES = [
        'visit_photos', 'emergency_reports', 'signatures_reports',
        'capture_coordinates', 'audit_security_logs', 'backups',
    ];

    /** Optional precision columns other modules may add next to lat/lng. */
    private const COORDINATE_EXTRAS = ['accuracy', 'accuracy_m', 'location_accuracy', 'gps_accuracy'];

    private const REDACTED_TEXT = '[حُذف بانتهاء مدة الاحتفاظ]';

    /** @var array<int, true>|null */
    private ?array $held = null;

    public function __construct(private readonly ?string $logDirectory = null) {}

    /**
     * @return array<string, array{days: int|null, cutoff: string|null, candidates: int, held: int, purged: int, failed: int, note: string|null}>
     */
    public function run(bool $execute): array
    {
        $this->held = null;
        $report = [];

        foreach (self::CATEGORIES as $category) {
            $days = $this->days($category);
            $row = ['days' => $days, 'cutoff' => null, 'candidates' => 0, 'held' => 0, 'purged' => 0, 'failed' => 0, 'note' => null];

            if ($days === null) {
                $report[$category] = ['note' => 'retention period not configured — skipped'] + $row;

                continue;
            }

            $cutoff = CarbonImmutable::now()->subDays($days);
            $row['cutoff'] = $cutoff->toIso8601String();

            try {
                $row = array_merge($row, $this->{'purge'.Str::studly($category)}($cutoff, $execute));
            } catch (Throwable $exception) {
                $row['failed']++;
                $row['note'] = 'error: '.$exception->getMessage();
            }

            $report[$category] = $row;
        }

        return $report;
    }

    public function days(string $category): ?int
    {
        $value = config("darak.privacy.retention_days.{$category}");
        $days = filter_var($value, FILTER_VALIDATE_INT);

        return $days !== false && $days > 0 ? $days : null;
    }

    /** @return array<string, int|string|null> */
    private function purgeVisitPhotos(CarbonImmutable $cutoff, bool $execute): array
    {
        return $this->purgeMedia($cutoff, $execute, fn (Builder $query) => $query->where('kind', '!=', 'signature'));
    }

    /** @return array<string, int|string|null> */
    private function purgeSignaturesReports(CarbonImmutable $cutoff, bool $execute): array
    {
        $result = $this->purgeMedia($cutoff, $execute, fn (Builder $query) => $query->where('kind', 'signature'));
        $held = $this->heldVisits();
        $disk = $this->disk();

        // Generated visit reports embed the photos and the signature.
        foreach ($this->expiredVisits($cutoff)->pluck('id') as $visitId) {
            $path = "reports/visits/visit-{$visitId}.pdf";

            if (! $disk->exists($path)) {
                continue;
            }

            $result['candidates']++;

            if (isset($held[(int) $visitId])) {
                $result['held']++;
            } elseif ($execute) {
                $disk->delete($path) ? $result['purged']++ : $result['failed']++;
            }
        }

        return $result;
    }

    /** @return array<string, int|string|null> */
    private function purgeEmergencyReports(CarbonImmutable $cutoff, bool $execute): array
    {
        $result = ['candidates' => 0, 'held' => 0, 'purged' => 0, 'failed' => 0];
        $disk = $this->disk();

        // Only closed reports: new/triaged ones are still being handled.
        DB::table('emergency_reports')
            ->whereIn('status', [EmergencyReport::STATUS_CONVERTED, EmergencyReport::STATUS_REJECTED])
            ->where('created_at', '<=', $cutoff)
            ->whereNull('purged_at')
            ->chunkById(500, function (Collection $rows) use (&$result, $disk, $execute): void {
                foreach ($rows as $row) {
                    $result['candidates']++;

                    if ($row->work_order_id !== null && $this->workOrderBlocked((int) $row->work_order_id)) {
                        $result['held']++;

                        continue;
                    }

                    if (! $execute) {
                        continue;
                    }

                    try {
                        $this->deleteFiles($disk, [$row->photo_path]);
                        DB::table('emergency_reports')->where('id', $row->id)->update([
                            'reporter_name' => self::REDACTED_TEXT,
                            'reporter_phone' => '',
                            'reporter_role' => null,
                            'description' => self::REDACTED_TEXT,
                            'photo_path' => null,
                            'photo_mime' => null,
                            'ip_hash' => null,
                            'purged_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $result['purged']++;
                    } catch (Throwable) {
                        $result['failed']++;
                    }
                }
            });

        return $result;
    }

    /** @return array<string, int|string|null> */
    private function purgeCaptureCoordinates(CarbonImmutable $cutoff, bool $execute): array
    {
        $result = ['candidates' => 0, 'held' => 0, 'purged' => 0, 'failed' => 0];
        $held = $this->heldVisits();
        $visits = $this->expiredVisits($cutoff)->pluck('id')->map(fn ($id): int => (int) $id);
        [$blocked, $eligible] = $visits->partition(fn (int $id): bool => isset($held[$id]));

        $tables = [
            'media_files' => ['visit_id', ['lat', 'lng']],
            'visit_events' => ['visit_id', ['lat', 'lng']],
            // The last en-route position of the technician.
            'visits' => ['id', ['technician_lat', 'technician_lng']],
        ];

        foreach ($tables as $table => [$key, $columns]) {
            $columns = [...$columns, ...array_filter(self::COORDINATE_EXTRAS, fn (string $column): bool => Schema::hasColumn($table, $column))];
            $located = fn (Collection $ids): Builder => DB::table($table)->whereIn($key, $ids->all())
                ->where(function (Builder $query) use ($columns): void {
                    foreach ($columns as $column) {
                        $query->orWhereNotNull($column);
                    }
                });

            foreach ($blocked->chunk(1000) as $chunk) {
                $count = $located($chunk)->count();
                $result['candidates'] += $count;
                $result['held'] += $count;
            }

            foreach ($eligible->chunk(1000) as $chunk) {
                $count = $located($chunk)->count();
                $result['candidates'] += $count;

                if ($execute && $count > 0) {
                    $result['purged'] += $located($chunk)->update(array_fill_keys($columns, null));
                }
            }
        }

        return $result;
    }

    /** @return array<string, int|string|null> */
    private function purgeAuditSecurityLogs(CarbonImmutable $cutoff, bool $execute): array
    {
        $result = ['candidates' => 0, 'held' => 0, 'purged' => 0, 'failed' => 0];
        $targets = [];

        // Sessions and tokens carry IP addresses and user agents.
        if (Schema::hasTable('sessions')) {
            $targets[] = DB::table('sessions')->where('last_activity', '<', $cutoff->getTimestamp());
        }

        if (Schema::hasTable('password_reset_tokens')) {
            $targets[] = DB::table('password_reset_tokens')->where('created_at', '<', $cutoff);
        }

        if (Schema::hasTable('personal_access_tokens')) {
            $targets[] = DB::table('personal_access_tokens')->whereNotNull('expires_at')->where('expires_at', '<', $cutoff);
            $lifetime = (int) config('sanctum.expiration');

            if ($lifetime > 0) {
                $targets[] = DB::table('personal_access_tokens')->whereNull('expires_at')
                    ->where('created_at', '<', $cutoff->subMinutes($lifetime));
            }
        }

        foreach ($targets as $query) {
            $count = (clone $query)->count();
            $result['candidates'] += $count;

            if ($execute && $count > 0) {
                $result['purged'] += $query->delete();
            }
        }

        foreach (File::glob(($this->logDirectory ?? storage_path('logs')).DIRECTORY_SEPARATOR.'*.log') as $file) {
            if ((int) filemtime($file) >= $cutoff->getTimestamp()) {
                continue;
            }

            $result['candidates']++;

            if ($execute) {
                @unlink($file) ? $result['purged']++ : $result['failed']++;
            }
        }

        $immutable = DB::table('audit_logs')->where('created_at', '<', $cutoff)->count();
        $result['note'] = "audit_logs kept: immutable hash chain ({$immutable} entries older than the cutoff)";

        return $result;
    }

    /** @return array<string, int|string|null> */
    private function purgeBackups(CarbonImmutable $cutoff, bool $execute): array
    {
        $result = ['candidates' => 0, 'held' => 0, 'purged' => 0, 'failed' => 0];
        $backups = new BackupService((string) (config('darak.backup_path') ?: storage_path('app/backups')));
        $archives = $backups->archives();
        $newest = array_key_first($archives);

        foreach ($archives as $path => $timestamp) {
            if ($timestamp >= $cutoff->getTimestamp()) {
                continue;
            }

            $result['candidates']++;

            // However old, the newest archive is the only way back.
            if ($path === $newest) {
                $result['held']++;
            } elseif ($execute) {
                @unlink($path) ? $result['purged']++ : $result['failed']++;
            }
        }

        // Plain-text dumps extracted by darak:restore hold the whole database.
        foreach (File::glob($backups->backupRoot().DIRECTORY_SEPARATOR.'darak-backup-*.sql') as $dump) {
            if ((int) filemtime($dump) >= $cutoff->getTimestamp()) {
                continue;
            }

            $result['candidates']++;

            if ($execute) {
                @unlink($dump) ? $result['purged']++ : $result['failed']++;
            }
        }

        $result['note'] = 'local archives only; apply the same age to the off-site bucket with a lifecycle rule';

        return $result;
    }

    /**
     * @param  Closure(Builder): mixed  $scope
     * @return array<string, int>
     */
    private function purgeMedia(CarbonImmutable $cutoff, bool $execute, Closure $scope): array
    {
        $result = ['candidates' => 0, 'held' => 0, 'purged' => 0, 'failed' => 0];
        $held = $this->heldVisits();
        $disk = $this->disk();
        $query = DB::table('media_files')
            ->whereIn('visit_id', $this->expiredVisits($cutoff))
            ->whereNull('purged_at')
            ->where(fn (Builder $paths) => $paths->whereNotNull('original_path')->orWhereNotNull('derived_path'));
        $scope($query);

        $query->chunkById(500, function (Collection $rows) use (&$result, $held, $disk, $execute): void {
            foreach ($rows as $row) {
                $result['candidates']++;

                if (isset($held[(int) $row->visit_id])) {
                    $result['held']++;

                    continue;
                }

                if (! $execute) {
                    continue;
                }

                try {
                    $this->deleteFiles($disk, [$row->original_path, $row->derived_path]);
                    DB::table('media_files')->where('id', $row->id)->update([
                        'original_path' => null,
                        'derived_path' => null,
                        'purged_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $result['purged']++;
                } catch (Throwable) {
                    $result['failed']++;
                }
            }
        });

        return $result;
    }

    /** Completed visits closed on or before the cutoff. */
    private function expiredVisits(CarbonImmutable $cutoff): Builder
    {
        return DB::table('visits')
            ->select('id')
            ->where('state', Visit::STATE_COMPLETED)
            ->where(fn (Builder $closed) => $closed->where('closed_at', '<=', $cutoff)
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('closed_at')->where('state_changed_at', '<=', $cutoff)));
    }

    /** @return array<int, true> */
    private function heldVisits(): array
    {
        if ($this->held !== null) {
            return $this->held;
        }

        $ids = collect();

        if (Schema::hasTable('report_disputes')) {
            $ids = $ids->merge(DB::table('report_disputes')->whereNotIn('status', ['resolved', 'rejected'])->pluck('visit_id'));
        }

        if (Schema::hasTable('visit_feedback')) {
            $ids = $ids->merge(DB::table('visit_feedback')->where('is_complaint', true)->where('status', '!=', 'resolved')->pluck('visit_id'));
        }

        if (Schema::hasTable('external_documents')) {
            $pending = fn (): Builder => DB::table('external_documents')->whereIn('status', ['requested', 'failed']);
            $ids = $ids->merge($pending()->whereNotNull('visit_id')->pluck('visit_id'));
            $ids = $ids->merge(DB::table('visits')
                ->whereIn('work_order_id', $pending()->whereNotNull('work_order_id')->select('work_order_id'))
                ->pluck('id'));
        }

        if (Schema::hasTable('contract_installments')) {
            $arrears = DB::table('contract_installments')
                ->whereColumn('paid_amount', '<', 'total_amount')
                ->where('due_on', '<', today()->toDateString())
                ->select('contract_id');
            $ids = $ids->merge(DB::table('visits')
                ->join('work_orders', 'work_orders.id', '=', 'visits.work_order_id')
                ->whereIn('work_orders.contract_id', $arrears)
                ->pluck('visits.id'));
        }

        return $this->held = $ids->filter()->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
    }

    /** A converted emergency stays while its work is open or on hold. */
    private function workOrderBlocked(int $workOrderId): bool
    {
        $held = $this->heldVisits();

        return DB::table('visits')->where('work_order_id', $workOrderId)->get(['id', 'state'])
            ->contains(fn (object $visit): bool => $visit->state !== Visit::STATE_COMPLETED || isset($held[(int) $visit->id]));
    }

    /** @param array<int, string|null> $paths */
    private function deleteFiles(Filesystem $disk, array $paths): void
    {
        foreach (array_filter($paths) as $path) {
            if ($disk->exists($path) && ! $disk->delete($path)) {
                throw new RuntimeException("Cannot delete [{$path}].");
            }
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('darak.files_disk', 'local'));
    }
}
