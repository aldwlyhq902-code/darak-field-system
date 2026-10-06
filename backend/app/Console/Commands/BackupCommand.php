<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use App\Services\HeartbeatPinger;
use App\Services\OffsiteBackupService;
use App\Services\OpsAlertService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Create → verify → copy off-site → rotate → heartbeat.
 *
 * Each later step runs only after the earlier ones succeeded, and nothing ever
 * deletes the archive that was just verified. Every failure reaches the owner
 * (critical log → optional webhook, plus an in-app notice) and the external
 * backup heartbeat is pinged only when the whole chain succeeded, so a broken
 * remote shows up as a missed check-in too.
 */
class BackupCommand extends Command
{
    protected $signature = 'darak:backup
        {--label= : Optional suffix for the archive name}
        {--no-offsite : Skip the off-site copy for this run}
        {--no-rotate : Skip retention rotation for this run}';

    protected $description = 'Create a verified backup archive, copy it off-site, and rotate old archives.';

    public function handle(BackupService $backups, OffsiteBackupService $offsite, OpsAlertService $alerts, HeartbeatPinger $pinger): int
    {
        try {
            $result = $backups->create($this->option('label'));
        } catch (Throwable $exception) {
            $alerts->raise('backup_failed', 'فشل النسخ الاحتياطي', 'تعذر إنشاء النسخة الاحتياطية: '.$exception->getMessage());
            $this->error('فشل إنشاء النسخة: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('أُنشئت النسخة: '.$result['path']);
        $this->line('  السجلات: '.array_sum($result['manifest']['counts']).' صف عبر '.count($result['manifest']['counts']).' جدولاً');
        $this->line('  الملفات: '.count($result['manifest']['files']));

        try {
            $verification = $backups->verify($result['path']);
        } catch (Throwable $exception) {
            $verification = ['ok' => false, 'error' => $exception->getMessage()];
        }

        if (! $verification['ok']) {
            $alerts->raise('backup_verify_failed', 'فشل التحقق من النسخة الاحتياطية', 'النسخة '.basename($result['path']).' لم تطابق قاعدة البيانات والملفات فور إنشائها.');
            $this->error('فشل التحقق من النسخة فور إنشائها — لا تعتمد عليها.');

            return self::FAILURE;
        }

        $this->info('تحقق فوري: مطابقة كاملة للسجلات وبصمات الملفات.');
        $healthy = true;

        if ($offsite->configured() && ! $this->option('no-offsite')) {
            $copy = $offsite->copy($result['path']);

            if ($copy['ok']) {
                $this->info('نُسخت النسخة خارج الخادم إلى '.$offsite->remote());
            } else {
                $healthy = false;
                $alerts->raise('backup_offsite_failed', 'فشل نسخ النسخة الاحتياطية خارج الخادم', 'rclone أعاد '.($copy['exit_code'] ?? 'خطأ').': '.$copy['error'].'. النسخة المحلية سليمة ولم تُحذف.');
                $this->error('فشل النسخ خارج الخادم: '.$copy['error'].' — بقيت النسخة المحلية كما هي.');
            }
        } elseif (! $offsite->configured()) {
            $this->warn('لا يوجد نسخ خارج الخادم (DARAK_BACKUP_RCLONE_REMOTE غير مضبوط).');
        }

        if (! $this->option('no-rotate')) {
            $healthy = $this->rotate($backups, $alerts, $result['path']) && $healthy;
        }

        if (! $healthy) {
            return self::FAILURE;
        }

        if ($pinger->ping(config('darak.backup_heartbeat_url'), 'backup')) {
            $this->line('  أُرسل نبض النسخ الاحتياطي.');
        }

        return self::SUCCESS;
    }

    private function rotate(BackupService $backups, OpsAlertService $alerts, string $protect): bool
    {
        $policy = (array) config('darak.backup_rotation', []);

        try {
            $rotation = $backups->rotate(
                (int) ($policy['daily'] ?? 7),
                (int) ($policy['weekly'] ?? 4),
                (int) ($policy['monthly'] ?? 6),
                $protect,
            );
        } catch (Throwable $exception) {
            $rotation = ['kept' => [], 'deleted' => [], 'failed' => ['rotation: '.$exception->getMessage()]];
        }

        $this->line(sprintf('  التدوير: أُبقي %d · حُذف %d', count($rotation['kept']), count($rotation['deleted'])));

        if ($rotation['failed'] === []) {
            return true;
        }

        $alerts->raise('backup_rotation_failed', 'فشل تدوير النسخ الاحتياطية', 'تعذر حذف: '.implode('، ', array_map('basename', $rotation['failed'])));
        $this->error('فشل تدوير بعض النسخ القديمة.');

        return false;
    }
}
