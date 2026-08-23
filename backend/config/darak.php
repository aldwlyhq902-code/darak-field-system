<?php

return [
    /*
    | E-invoicing provider. Bought, never built — Darak stores a reference and a
    | status only. 'fake' is a deterministic stand-in used in development and tests.
    */
    'invoice_provider' => env('DARAK_INVOICE_PROVIDER', 'fake'),

    /*
    | Default service window. The SLA countdown runs ONLY inside it; time outside is
    | frozen. Per-contract values override this.
    */
    'service_window' => [
        'start' => env('DARAK_SERVICE_START', '07:00:00'),
        'end' => env('DARAK_SERVICE_END', '23:00:00'),
    ],

    /*
    | A repeat on the same asset inside this window is auto-flagged as rework.
    | Only a supervisor may reclassify it.
    */
    'rework_window_days' => (int) env('DARAK_REWORK_WINDOW_DAYS', 30),

    /*
    | Cap on one continuous on-site stretch, measured from the device event chain.
    | Longer than any real shift, so it only bites on a wound-forward clock — and
    | ClockGuard flags that separately rather than the duration being silently
    | rewritten.
    */
    'max_on_site_segment_hours' => (int) env('DARAK_MAX_ON_SITE_SEGMENT_HOURS', 16),

    /*
    | Recovery objectives for the backup drill (acceptance criterion 8).
    */
    'rpo_hours' => 24,
    'rto_hours' => 4,

    /*
    | Backups belong outside the deploy directory in production, so a bad release
    | cannot remove them with the code. Empty falls back to storage/app/backups.
    */
    'backup_path' => env('DARAK_BACKUP_PATH', ''),

    /*
    | Optional absolute pg_dump path. Leaving it empty uses PATH and, on Windows,
    | the newest standard PostgreSQL installation under Program Files.
    */
    'pg_dump_path' => env('DARAK_PG_DUMP_PATH'),

    /*
    | Evidence uploads are authenticated but still untrusted input. A declared
    | size and a server-side ceiling prevent a compromised device from filling
    | the disk one valid 8MB chunk at a time.
    */
    'max_media_bytes' => (int) env('DARAK_MAX_MEDIA_BYTES', 25 * 1024 * 1024),

    /*
    | Production backups contain client data, signatures and location evidence.
    | BackupService refuses to create an unencrypted production archive.
    */
    'backup_password' => env('DARAK_BACKUP_PASSWORD'),

    /*
    | Production is fail-closed until the owner and privacy adviser approve the
    | policy inputs. No retention period has a developer-selected default.
    */
    'privacy' => [
        'controller_name' => env('DARAK_PRIVACY_CONTROLLER_NAME'),
        'request_channel' => env('DARAK_PRIVACY_REQUEST_CHANNEL'),
        'providers_register' => env('DARAK_PRIVACY_PROVIDERS_REGISTER'),
        'request_procedure' => env('DARAK_PRIVACY_REQUEST_PROCEDURE'),
        'notice_version' => env('DARAK_PRIVACY_NOTICE_VERSION'),
        'emergency_consent_version' => env('DARAK_PRIVACY_EMERGENCY_CONSENT_VERSION'),
        'retention_days' => [
            'visit_photos' => env('DARAK_RETENTION_VISIT_PHOTOS_DAYS'),
            'emergency_reports' => env('DARAK_RETENTION_EMERGENCY_REPORTS_DAYS'),
            'signatures_reports' => env('DARAK_RETENTION_SIGNATURES_REPORTS_DAYS'),
            'capture_coordinates' => env('DARAK_RETENTION_CAPTURE_COORDINATES_DAYS'),
            'audit_security_logs' => env('DARAK_RETENTION_AUDIT_LOGS_DAYS'),
            'backups' => env('DARAK_RETENTION_BACKUPS_DAYS'),
        ],
    ],

    // Vercel's writable filesystem is ephemeral. It is supported only as an
    // explicitly opted-in demo surface and must never pass production preflight.
    'ephemeral_serverless' => (bool) env('VERCEL', false),

    'default_travel_minutes' => (int) env('DARAK_DEFAULT_TRAVEL_MINUTES', 30),
    'routing' => [
        // Set to mapbox to use the live driving-traffic API. A short timeout and
        // deterministic local fallback keep dispatch usable during provider outages.
        'provider' => env('DARAK_ROUTING_PROVIDER', 'local'),
        'mapbox_token' => env('DARAK_MAPBOX_TOKEN'),
    ],
    'prediction' => ['minimum_samples' => (int) env('DARAK_PREDICTION_MINIMUM_SAMPLES', 500)],

    /*
    | Unvalidated analytics stay out of the production MVP unless the owner opts
    | in explicitly. This gates both technician ranking and fault prediction.
    */
    'experimental_analytics' => (bool) env('DARAK_ENABLE_EXPERIMENTAL_ANALYTICS', false),

    /*
    | Deliberately ABSENT from the MVP, recorded here so nobody re-adds them by
    | accident. Each was removed for a documented reason (PRD v1.2 §3):
    |   - global heat threshold: unproven; limits are per-SKU from the maker's sheet
    |   - commission clawback: cannot deduct from earned wages without legal review
    |   - banned-word engine: a word list is not legal protection
    |   - hard-coded labour-law conclusions: supplied by a qualified HR specialist
    */
    'excluded_from_mvp' => [
        'global_heat_threshold',
        'commission_clawback',
        'banned_word_engine',
        'hardcoded_labour_rules',
    ],
];
