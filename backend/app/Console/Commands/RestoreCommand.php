<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * The restore drill. Run it monthly on a clean environment and record the result —
 * an untested backup is a guess.
 */
class RestoreCommand extends Command
{
    protected $signature = 'darak:restore {archive : Path to the .zip produced by darak:backup}
                            {--verify-only : Compare the archive to the current database without writing anything}
                            {--force : Required to actually write, since this overwrites data}';

    protected $description = 'Restore or verify a Darak backup archive.';

    public function handle(BackupService $backups): int
    {
        $archive = $this->argument('archive');

        if ($this->option('verify-only')) {
            $result = $backups->verify($archive);

            if ($result['ok']) {
                $this->info('الأرشيف مطابق للحالة الحالية.');

                return self::SUCCESS;
            }

            foreach ($result['mismatched_counts'] as $table => $counts) {
                $this->warn("  {$table}: متوقع {$counts['expected']} · فعلي {$counts['actual']}");
            }

            foreach ($result['corrupt_files'] as $file) {
                $this->error("  بصمة لا تطابق: {$file}");
            }

            foreach ($result['missing_files'] as $file) {
                $this->error("  ملف مفقود من الأرشيف: {$file}");
            }

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->error('الاستعادة تكتب فوق البيانات الحالية. أضف --force إن كنت متعمداً، ويفضَّل أن تكون البيئة نظيفة.');

            return self::FAILURE;
        }

        $result = $backups->restore($archive);

        $this->info('اكتملت الاستعادة.');
        $this->line('  ملفات مستعادة: ' . $result['restored_files']);
        $this->line('  قاعدة البيانات: ' . ($result['database_restored'] ? 'استُعيدت' : 'تحتاج استعادة يدوية — راجع DEPLOYMENT.md'));
        $this->line('  نسخة بتاريخ: ' . ($result['manifest']['created_at'] ?? '—'));

        return self::SUCCESS;
    }
}
