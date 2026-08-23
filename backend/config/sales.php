<?php

return [
    'stale_after_days' => (int) env('SALES_STALE_AFTER_DAYS', 7),
    'vapid' => [
        'subject' => env('WEB_PUSH_VAPID_SUBJECT', 'mailto:admin@example.com'),
        'public_key' => env('WEB_PUSH_VAPID_PUBLIC_KEY'),
        'private_key' => env('WEB_PUSH_VAPID_PRIVATE_KEY'),
    ],
];
