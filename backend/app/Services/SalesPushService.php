<?php

namespace App\Services;

use App\Models\User;
use App\Models\WebPushSubscription;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class SalesPushService
{
    /**
     * The browser push services a real subscription can point at. The server
     * POSTs to the stored endpoint, so accepting any URL turned "subscribe to
     * notifications" into a request-forgery primitive against internal hosts.
     */
    private const ALLOWED_HOSTS = [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'web.push.apple.com',
    ];

    private const ALLOWED_HOST_SUFFIXES = [
        '.push.services.mozilla.com',
        '.notify.windows.com',
        '.push.apple.com',
    ];

    public function configured(): bool
    {
        return filled(config('sales.vapid.public_key')) && filled(config('sales.vapid.private_key'));
    }

    /** https only, a known push service host, no IP literal, credentials or odd port. */
    public static function isAllowedEndpoint(mixed $url): bool
    {
        if (! is_string($url) || $url === '' || strlen($url) > 2048) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || str_ends_with($host, '.') || str_starts_with($host, '[')
            || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        if (in_array($host, self::ALLOWED_HOSTS, true)) {
            return true;
        }

        foreach (self::ALLOWED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    public function subscribe(User $user, array $payload): WebPushSubscription
    {
        if (! self::isAllowedEndpoint($payload['endpoint'] ?? null)) {
            throw new \InvalidArgumentException('Push endpoint is not an allowed push service.');
        }

        return WebPushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => hash('sha256', $payload['endpoint'])],
            [
                'user_id' => $user->id, 'endpoint' => $payload['endpoint'],
                'public_key' => $payload['keys']['p256dh'], 'auth_token' => $payload['keys']['auth'],
                'content_encoding' => $payload['contentEncoding'] ?? 'aes128gcm', 'last_used_at' => now(),
            ],
        );
    }

    /** @param array<string, mixed> $data */
    public function send(User $user, string $title, string $body, array $data = []): int
    {
        if (! $this->configured()) {
            return 0;
        }
        $webPush = new WebPush(['VAPID' => [
            'subject' => config('sales.vapid.subject'),
            'publicKey' => config('sales.vapid.public_key'),
            'privateKey' => config('sales.vapid.private_key'),
        ]], ['TTL' => 3600, 'urgency' => 'normal'], $this->httpClient());
        $subscriptions = $user->webPushSubscriptions()->get();
        foreach ($subscriptions as $stored) {
            // Re-checked at send time: rows stored before the allowlist existed
            // (or edited in the database) never reach the HTTP client.
            if (! self::isAllowedEndpoint($stored->endpoint)) {
                Log::warning('Sales web push endpoint refused', ['subscription_id' => $stored->id]);
                $stored->delete();

                continue;
            }

            $webPush->queueNotification(Subscription::create([
                'endpoint' => $stored->endpoint,
                'keys' => ['p256dh' => $stored->public_key, 'auth' => $stored->auth_token],
                'contentEncoding' => $stored->content_encoding,
            ]), json_encode(['title' => $title, 'body' => $body, 'url' => route('sales.home', absolute: false)] + $data, JSON_UNESCAPED_UNICODE));
        }

        $sent = 0;
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
            } elseif ($report->isSubscriptionExpired()) {
                WebPushSubscription::query()->where('endpoint_hash', hash('sha256', $report->getEndpoint()))->delete();
            } else {
                Log::warning('Sales web push delivery failed', ['reason' => $report->getReason()]);
            }
        }

        return $sent;
    }

    /**
     * No redirects: an allowed host answering 30x must not bounce the request
     * to an address the allowlist never saw. Short timeouts keep a slow push
     * service from holding a worker.
     */
    protected function httpClient(): Client
    {
        return new Client([
            'allow_redirects' => false,
            'connect_timeout' => 5,
            'timeout' => 10,
        ]);
    }
}
