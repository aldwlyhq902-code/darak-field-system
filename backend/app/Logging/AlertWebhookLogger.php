<?php

namespace App\Logging;

use Monolog\Level;
use Monolog\Logger;

/**
 * Factory for the optional `alert_webhook` log channel (config/logging.php).
 * Joined to the stack only when LOG_ALERT_WEBHOOK_URL is set.
 */
class AlertWebhookLogger
{
    /** @param array<string, mixed> $config */
    public function __invoke(array $config): Logger
    {
        return new Logger('alert_webhook', [
            new AlertWebhookHandler(
                (string) ($config['url'] ?? ''),
                Level::fromName((string) ($config['level'] ?? 'critical')),
            ),
        ]);
    }
}
