<?php

namespace App\Services;

use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class SalesPushService
{
    public function configured(): bool
    {
        return filled(config('sales.vapid.public_key')) && filled(config('sales.vapid.private_key'));
    }

    /** @param array<string, mixed> $payload */
    public function subscribe(User $user, array $payload): WebPushSubscription
    {
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
        ]], ['TTL' => 3600, 'urgency' => 'normal']);
        $subscriptions = $user->webPushSubscriptions()->get();
        foreach ($subscriptions as $stored) {
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
}
