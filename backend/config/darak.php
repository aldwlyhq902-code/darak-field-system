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

    'monitoring' => [
        'scheduler_max_age_seconds' => (int) env('DARAK_SCHEDULER_MAX_AGE_SECONDS', 300),
        'backup_grace_hours' => (int) env('DARAK_BACKUP_GRACE_HOURS', 2),
        'minimum_free_disk_bytes' => (int) env('DARAK_MINIMUM_FREE_DISK_BYTES', 1073741824),
    ],

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

    // Required only when intentionally creating non-production demo accounts.
    'demo_password' => env('DARAK_DEMO_PASSWORD'),

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

    // ops
    /*
    | Audit log hardening. New rows are chained with HMAC-SHA256 under this key
    | (hash_version 2); older rows keep verifying as plain SHA-256 (version 1).
    | The key must never change once rows exist — keep it in the secrets manager
    | with the backup password. Rows are immutable, so personal data is redacted
    | at write time and replaced by a short keyed fingerprint investigators can
    | still correlate. IPs are truncated (/24, /48) and user agents hashed unless
    | the owner explicitly opts into full values.
    */
    'audit' => [
        'hmac_key' => env('DARAK_AUDIT_HMAC_KEY'),
        'keep_full_ip' => (bool) env('DARAK_AUDIT_KEEP_FULL_IP', false),
        'redact_keys' => [
            'phone', 'mobile', '*_phone', 'phone_*', 'email', '*_email', 'contact_name',
            'emergency_contact*', 'national_id', 'iqama*', 'passport*', 'document_number',
            'reporter_name', 'reporter_role', 'password', 'token', '*_token', 'two_factor_*', 'secret',
        ],
        // Free text is redacted only for actions whose text routinely carries
        // personal or dispute detail; elsewhere notes are operational.
        'redact_free_text_keys' => [
            'description', 'notes', 'note', 'comment', 'reason', 'resolution_note',
            'response_note', 'supervisor_note',
        ],
        'redact_free_text_actions' => [
            'report.*', 'report_dispute.*', 'sales.*', 'employee.*', 'visit_feedback.*',
            'emergency.*', 'client_service_request.*',
        ],
    ],

    /*
    | Backup rotation after every verified nightly run: the newest archive of each
    | of the last N days, weeks and months is kept. The archive just created is
    | never deleted. DARAK_RETENTION_BACKUPS_DAYS (privacy) is a separate maximum
    | age enforced by darak:retention-purge.
    */
    'backup_rotation' => [
        'daily' => (int) env('DARAK_BACKUP_KEEP_DAILY', 7),
        'weekly' => (int) env('DARAK_BACKUP_KEEP_WEEKLY', 4),
        'monthly' => (int) env('DARAK_BACKUP_KEEP_MONTHLY', 6),
    ],

    /*
    | Off-site copy after each verified backup: an rclone target such as
    | "offsite:darak-backups/prod". Empty disables it (preflight then fails when
    | evidence lives on the local disk).
    */
    'backup_rclone_remote' => env('DARAK_BACKUP_RCLONE_REMOTE'),
    'rclone_path' => env('DARAK_RCLONE_PATH', 'rclone'),
    'rclone_timeout_seconds' => (int) env('DARAK_RCLONE_TIMEOUT_SECONDS', 3600),

    /*
    | Dead-man's-switch URLs (healthchecks.io, UptimeRobot heartbeat). The
    | scheduler pings every five minutes; the backup pings after a fully
    | successful run. Empty disables the ping.
    */
    'heartbeat_url' => env('DARAK_HEARTBEAT_URL'),
    'backup_heartbeat_url' => env('DARAK_BACKUP_HEARTBEAT_URL'),
];
