<?php

namespace App\Logging;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Posts critical errors to a Slack-compatible incoming webhook as {"text": …}.
 * Discord accepts the same payload on its `/slack` webhook URL; Mattermost and
 * Rocket.Chat accept it natively.
 *
 * Deliberately not Monolog's SlackWebhookHandler: that one has no timeout and
 * retries up to five times inline, so a slow chat provider would stall the very
 * request that is already failing. This one waits at most a few seconds, sends
 * the same message at most once per ten minutes, never forwards the log context
 * (it may hold personal data) and swallows its own failures.
 */
class AlertWebhookHandler extends AbstractProcessingHandler
{
    private const THROTTLE_SECONDS = 600;

    public function __construct(private readonly string $url, Level $level = Level::Critical)
    {
        parent::__construct($level, true);
    }

    protected function write(LogRecord $record): void
    {
        if (trim($this->url) === '') {
            return;
        }

        try {
            $fingerprint = sha1($record->level->name.'|'.$record->message);

            if (! Cache::add('darak:alert-webhook:'.$fingerprint, 1, self::THROTTLE_SECONDS)) {
                return;
            }
        } catch (Throwable) {
            // Cache down: better a duplicate alert than none.
        }

        try {
            Http::timeout(4)->connectTimeout(2)->asJson()->post($this->url, ['text' => $this->text($record)]);
        } catch (Throwable $exception) {
            error_log('[darak-alert-webhook] delivery failed: '.get_debug_type($exception));
        }
    }

    private function text(LogRecord $record): string
    {
        $exception = $record->context['exception'] ?? null;
        // SQL errors carry bound values (phones, names); send the state only.
        $detail = match (true) {
            $exception instanceof QueryException => 'QueryException SQLSTATE '.$exception->getCode(),
            $exception instanceof Throwable => get_debug_type($exception).': '.$exception->getMessage(),
            default => $record->message,
        };
        $requestId = $record->context['request_id'] ?? null;

        $text = sprintf(
            '[%s · %s] %s: %s%s',
            (string) config('app.name', 'Darak'),
            (string) config('app.env', 'production'),
            $record->level->getName(),
            $detail,
            is_string($requestId) ? ' (request '.$requestId.')' : '',
        );

        return mb_strlen($text) > 1500 ? mb_substr($text, 0, 1500).'…' : $text;
    }
}
