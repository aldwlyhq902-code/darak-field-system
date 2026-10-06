<?php

declare(strict_types=1);

// Vercel has no persistent writable filesystem for evidence or upload chunks.
// Keep this adapter demo-only and fail closed unless an operator explicitly
// opts into a non-production demonstration that may lose all runtime data.
$demoOptIn = filter_var(getenv('DARAK_VERCEL_EPHEMERAL_DEMO'), FILTER_VALIDATE_BOOL);
$environment = getenv('APP_ENV') ?: 'production';
// A public demo with debug pages would print environment variables and stack
// traces to anyone. Refuse rather than silently override an explicit request.
$debugRequested = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL);

if (! $demoOptIn || $environment === 'production' || $debugRequested) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($debugRequested && $demoOptIn && $environment !== 'production' ? [
        'code' => 'DEBUG_NOT_ALLOWED',
        'message' => 'The Vercel demo refuses to run with APP_DEBUG enabled.',
    ] : [
        'code' => 'EPHEMERAL_RUNTIME_DISABLED',
        'message' => 'Vercel is demo-only and cannot store production evidence.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// Pin the safe values before Laravel reads its environment: debug off, and
// Vercel's TLS terminator (no fixed address) trusted for forwarded headers.
foreach (['APP_DEBUG' => 'false', 'TRUSTED_PROXIES' => '*'] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

// Vercel Functions only allow runtime writes under /tmp. Laravel needs a
// writable storage tree for compiled Blade views, cache files, sessions and
// logs, so prepare it before the framework is bootstrapped.
$storagePath = '/tmp/mihwar-storage';

foreach ([
    'app/public',
    'framework/cache/data',
    'framework/sessions',
    'framework/testing',
    'framework/views',
    'logs',
] as $directory) {
    $path = $storagePath.'/'.$directory;

    if (! is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

putenv('LARAVEL_STORAGE_PATH='.$storagePath);
$_ENV['LARAVEL_STORAGE_PATH'] = $storagePath;
$_SERVER['LARAVEL_STORAGE_PATH'] = $storagePath;

require __DIR__.'/../public/index.php';
