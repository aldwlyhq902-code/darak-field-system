<?php

return [
    /*
    | E-invoicing provider. Bought, never built — Darak stores a reference and a
    | status only. 'fake' is a deterministic stand-in used in development and tests;
    | 'manual' parks each document for the finance team to issue in an external
    | ZATCA-compliant tool and record here. Any other value fails on resolution
    | (see InvoiceProviderFactory) instead of silently falling back to the fake.
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
    |     (commission_clawback_days only voids commissions not yet earned, or
    |     offsets FUTURE commissions — never wages)
    |   - banned-word engine: a word list is not legal protection
    |   - hard-coded labour-law conclusions: supplied by a qualified HR specialist
    */
    'excluded_from_mvp' => [
        'global_heat_threshold',
        'commission_clawback',
        'banned_word_engine',
        'hardcoded_labour_rules',
    ],

    // tenancy
    /*
    | Quotation price control (pre-VAT, per billing month, per package). A
    | quotation written by anyone but the owner cannot be sent until it passes
    | the dual financial approval when its price is below the floor, or below
    | the package list price by more than the allowed discount percent. With no
    | list price configured for a package, every non-owner quotation for it
    | needs approval. Empty values mean "not configured".
    */
    'quotation_pricing' => [
        'list_prices' => [
            'basic' => env('DARAK_LIST_PRICE_BASIC'),
            'comprehensive' => env('DARAK_LIST_PRICE_COMPREHENSIVE'),
        ],
        'price_floor' => env('DARAK_QUOTATION_PRICE_FLOOR'),
        'max_discount_percent' => env('DARAK_QUOTATION_MAX_DISCOUNT_PERCENT', 0),
    ],

    // auth & api
    /*
    | Per-account lockouts sit on top of the per-IP throttles. A botnet rotating
    | addresses still meets the account counter; a typo-prone owner still gets
    | back in after the window. Password counters are keyed by the submitted
    | email, so an unknown address locks exactly like a real one (no enumeration).
    */
    'auth' => [
        'password_max_attempts' => (int) env('DARAK_PASSWORD_MAX_ATTEMPTS', 5),
        'password_lockout_minutes' => (int) env('DARAK_PASSWORD_LOCKOUT_MINUTES', 15),
        'mfa_max_attempts' => (int) env('DARAK_MFA_MAX_ATTEMPTS', 5),
        'mfa_lockout_minutes' => (int) env('DARAK_MFA_LOCKOUT_MINUTES', 15),
        // API tokens unused for this many days stop working (401, app re-logins).
        'token_idle_days' => (int) env('DARAK_TOKEN_IDLE_DAYS', 14),
        // Showing a new MFA secret requires a password proven this recently.
        'password_confirm_seconds' => (int) env('DARAK_PASSWORD_CONFIRM_SECONDS', 600),
    ],
    'api' => [
        // Per device token. Generous: a phone back from a dead zone flushes its
        // queue in batches of up to 200 events.
        'sync_events_per_minute' => (int) env('DARAK_SYNC_EVENTS_PER_MINUTE', 120),
        // Idempotency-Key responses are replayable for this long, then pruned.
        'idempotency_ttl_hours' => (int) env('DARAK_IDEMPOTENCY_TTL_HOURS', 48),
    ],
    // Public QR emergency form: per-site caps on top of the per-IP throttle.
    'emergency_reports' => [
        'per_site_hourly' => (int) env('DARAK_EMERGENCY_REPORTS_PER_SITE_HOURLY', 10),
        'per_site_daily' => (int) env('DARAK_EMERGENCY_REPORTS_PER_SITE_DAILY', 30),
    ],
    // Evidence images are inspected (header only) before GD decodes them.
    'media' => [
        'max_image_pixels' => (int) env('DARAK_MAX_IMAGE_PIXELS', 40_000_000),
        'max_image_side' => (int) env('DARAK_MAX_IMAGE_SIDE', 12_000),
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

    /*
    | darak:retention-purge is report-only (dry run) by default. Deleting for
    | real — by hand with --execute or nightly from the scheduler — stays off
    | until the owner and the privacy adviser approve the retention_days above.
    */
    'retention_purge_enabled' => (bool) env('DARAK_RETENTION_PURGE_ENABLED', false),

    /*
    | Module switches for SCHEDULED jobs only (routes/console.php); routes and
    | screens are unaffected. All default to on so behaviour is unchanged; the
    | owner can freeze a non-MVP module's background work by setting false.
    */
    'modules' => [
        'sales' => (bool) env('DARAK_MODULE_SALES', true),
        'commissions' => (bool) env('DARAK_MODULE_COMMISSIONS', true),
        'procurement_automation' => (bool) env('DARAK_MODULE_PROCUREMENT_AUTOMATION', true),
        'auto_reassign' => (bool) env('DARAK_MODULE_AUTO_REASSIGN', true),
        'fleet_hr_alerts' => (bool) env('DARAK_MODULE_FLEET_HR_ALERTS', true),
    ],

    /*
    | Client-portal map while a technician is en route (position rounded to about
    | 100 m). An https XYZ tile template with {z}/{x}/{y}; "none" draws no map and
    | sends nothing to a third party (text-only ETA). The OpenStreetMap default is
    | a cross-border recipient — see PRIVACY_AND_RETENTION.md.
    */
    'map_tiles_url' => env('DARAK_MAP_TILES_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
    'map_attribution' => env('DARAK_MAP_ATTRIBUTION', '© OpenStreetMap contributors'),

    // data
    /*
    | Disk for private uploads: employee/vehicle documents, inspection and
    | emergency photos, subcontractor documents, sales attachments, evidence
    | media and visit reports. They never get a public URL — authorised
    | controllers stream them. 'local' is storage/app/private (included in the
    | local backup). An S3-compatible disk ('s3') needs
    | `composer require league/flysystem-aws-s3-v3` and the AWS_* variables, with
    | public access blocked on the bucket; its files are then outside the local
    | backup. Changing the disk does not move files already stored.
    */
    'files_disk' => env('DARAK_FILES_DISK', 'local'),

    /*
    | Commission clawback window (PRD v1.2). An accrued commission becomes
    | earned after this many days unless its receipt is refunded or its contract
    | cancelled first. Commissions are never deducted from wages: a reversal
    | after payout is a negative adjustment against FUTURE commissions only.
    */
    'commission_clawback_days' => (int) env('DARAK_COMMISSION_CLAWBACK_DAYS', 30),
];
