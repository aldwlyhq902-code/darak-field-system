<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupCommand extends Command
{
    protected $signature = 'darak:backup {--label= : Optional suffix for the archive name}';

    protected $description = 'Create a verified backup archive of the database and evidence files.';

    public function handle(BackupService $backups): int
    {
        $result = $backups->create($this->option('label'));

        $this->info('أُنشئت النسخة: ' . $result['path']);
        $this->line('  السجلات: ' . array_sum($result['manifest']['counts']) . ' صف عبر ' . count($result['manifest']['counts']) . ' جدولاً');
        $this->line('  الملفات: ' . count($result['manifest']['files']));

        $verification = $backups->verify($result['path']);

        if (! $verification['ok']) {
            $this->error('فشل التحقق من النسخة فور إنشائها — لا تعتمد عليها.');

            return self::FAILURE;
        }

        $this->info('تحقق فوري: مطابقة كاملة للسجلات وبصمات الملفات.');

        return self::SUCCESS;
    }
}
