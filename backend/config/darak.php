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
