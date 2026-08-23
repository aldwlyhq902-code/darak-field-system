<?php

declare(strict_types=1);

// Vercel has no persistent writable filesystem for evidence or upload chunks.
// Keep this adapter demo-only and fail closed unless an operator explicitly
// opts into a non-production demonstration that may lose all runtime data.
$demoOptIn = filter_var(getenv('DARAK_VERCEL_EPHEMERAL_DEMO'), FILTER_VALIDATE_BOOL);
$environment = getenv('APP_ENV') ?: 'production';

if (! $demoOptIn || $environment === 'production') {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'code' => 'EPHEMERAL_RUNTIME_DISABLED',
        'message' => 'Vercel is demo-only and cannot store production evidence.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
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
