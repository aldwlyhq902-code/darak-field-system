<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Drains the outbox. In-app messages deliver here; manual-WhatsApp ones are left
 * for a human and are never marked sent on their behalf.
 */
class NotificationsRunCommand extends Command
{
    protected $signature = 'darak:notifications-run {--limit=100}';

    protected $description = 'Deliver queued notifications and back off the ones that fail.';

    public function handle(NotificationService $notifications): int
    {
        $delivered = 0;
        $awaiting = 0;
        $failed = 0;

        foreach ($notifications->due((int) $this->option('limit')) as $message) {
            try {
                $notifications->deliver($message) === 'sent' ? $delivered++ : $awaiting++;
            } catch (Throwable $e) {
                $notifications->recordFailure($message, $e->getMessage());
                $failed++;
            }
        }

        $this->info("أُرسل {$delivered} · بانتظار إرسال يدوي {$awaiting} · أخفق {$failed}.");

        return self::SUCCESS;
    }
}
