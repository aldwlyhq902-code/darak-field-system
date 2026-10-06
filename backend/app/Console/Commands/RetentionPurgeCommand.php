<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\OpsAlertService;
use App\Services\RetentionPurgeService;
use Illuminate\Console\Command;

/**
 * Report-first retention purge. Without --execute nothing is touched: the
 * owner and the privacy adviser read the dry-run report before any deletion.
 * Executed runs are refused until DARAK_RETENTION_PURGE_ENABLED=true and each
 * one leaves an audit entry with the per-category counts.
 */
class RetentionPurgeCommand extends Command
{
    protected $signature = 'darak:retention-purge
        {--execute : Delete for real. Without it the command only reports}
        {--dry-run : Report only (the default; wins over --execute)}
        {--json : Emit the report as JSON}';

    protected $description = 'Apply the approved retention periods (dry run unless --execute).';

    public function handle(RetentionPurgeService $purge, AuditLogger $audit, OpsAlertService $alerts): int
    {
        $execute = (bool) $this->option('execute') && ! $this->option('dry-run');

        if ($execute && ! config('darak.retention_purge_enabled')) {
            $this->error('الإتلاف الفعلي معطل. اعتمد المدد في PRIVACY_AND_RETENTION.md ثم اضبط DARAK_RETENTION_PURGE_ENABLED=true.');

            return self::FAILURE;
        }

        $report = $purge->run($execute);
        $failed = array_sum(array_column($report, 'failed'));

        if ($this->option('json')) {
            $this->line(json_encode([
                'mode' => $execute ? 'execute' : 'dry-run',
                'ran_at' => now()->toIso8601String(),
                'categories' => $report,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->info($execute ? 'تنفيذ الإتلاف وفق مدد الاحتفاظ المعتمدة' : 'تشغيل تجريبي (dry run) — لم يُحذف شيء');
            $this->table(
                ['Category', 'Days', 'Candidates', 'Held', $execute ? 'Purged' : 'Would purge', 'Failed', 'Note'],
                collect($report)->map(fn (array $row, string $category): array => [
                    $category,
                    $row['days'] ?? '—',
                    $row['candidates'],
                    $row['held'],
                    $execute ? $row['purged'] : $row['candidates'] - $row['held'],
                    $row['failed'],
                    $row['note'] ?? '',
                ])->values()->all(),
            );
        }

        if ($execute) {
            $audit->record('retention.purge_executed', null, null, ['categories' => $report]);
        }

        if ($failed > 0) {
            $alerts->raise('retention_purge_failed', 'تعذر إتمام إتلاف البيانات المنتهية', "فشل حذف {$failed} عنصراً؛ راجع darak:retention-purge.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
