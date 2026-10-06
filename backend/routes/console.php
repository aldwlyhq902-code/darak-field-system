<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
| Requires one cron entry on the server:
|   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
| See DEPLOYMENT.md and deploy/cron/darak.
|
| Gates are evaluated on every schedule:run, so flipping an env flag (and
| re-caching config) takes effect without touching this file:
|   - DARAK_ENABLE_EXPERIMENTAL_ANALYTICS  unvalidated analytics (default off)
|   - DARAK_MODULE_*                       owner can freeze non-MVP modules
|                                          (default on: behaviour unchanged)
|   - DARAK_RETENTION_PURGE_ENABLED        destructive retention purge (default off)
*/

$module = static fn (string $name): bool => (bool) config("darak.modules.{$name}", true);

// Liveness: records the heartbeat darak:health reads and, when DARAK_HEARTBEAT_URL
// is set, pings the external dead-man's switch every five minutes.
Schedule::command('darak:heartbeat')
    ->everyMinute()
    ->withoutOverlapping();

// SLA warnings. Every ten minutes is enough: the warning threshold is a quarter of
// the budget, and the shortest budget in use is two hours. The sweep itself skips
// visits whose service window is closed, so it stays quiet overnight.
Schedule::command('darak:sla-sweep')
    ->everyTenMinutes()
    ->withoutOverlapping();

// Drains the outbox. Manual-WhatsApp messages are left for a human.
Schedule::command('darak:notifications-run')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('darak:commercial-alerts')
    ->dailyAt('08:00')
    ->withoutOverlapping();

// Employee/vehicle document renewals and fleet maintenance due.
Schedule::command('darak:compliance-alerts')
    ->dailyAt('07:00')
    ->when(fn (): bool => $module('fleet_hr_alerts'))
    ->withoutOverlapping();

Schedule::command('darak:maintenance-generate')
    ->dailyAt('05:00')
    ->withoutOverlapping();

Schedule::command('darak:renewal-offers --days=45')
    ->dailyAt('07:30')
    ->withoutOverlapping();

Schedule::command('darak:replenishment-scan')
    ->dailyAt('06:00')
    ->when(fn (): bool => $module('procurement_automation'))
    ->withoutOverlapping();

Schedule::command('darak:reassign-unavailable')
    ->everyFifteenMinutes()
    ->when(fn (): bool => $module('auto_reassign'))
    ->withoutOverlapping();

Schedule::command('darak:commissions-generate')
    ->dailyAt('23:30')
    ->when(fn (): bool => $module('commissions'))
    ->withoutOverlapping();

// Sales follow-up alerts and the web push that delivers them.
Schedule::command('darak:sales-alerts')
    ->hourly()
    ->when(fn (): bool => $module('sales'))
    ->withoutOverlapping();

// Experimental analytics: never runs unless the owner opted in explicitly.
Schedule::command('darak:fault-model-train')
    ->weeklyOn(1, '03:30')
    ->when(fn (): bool => (bool) config('darak.experimental_analytics'))
    ->withoutOverlapping();

// Nightly backup, verified immediately after creation, then copied off-site and
// rotated. RPO is 24 hours, so a daily run is the contract — not a nice-to-have.
Schedule::command('darak:backup')
    ->dailyAt('02:30')
    ->withoutOverlapping();

// Retention purge after the nightly backup. Off until the owner and privacy
// adviser approve the periods; until then run it by hand as a dry-run report.
Schedule::command('darak:retention-purge --execute')
    ->dailyAt('04:15')
    ->when(fn (): bool => (bool) config('darak.retention_purge_enabled'))
    ->withoutOverlapping();

// Housekeeping for models using Prunable/MassPrunable (e.g. expired idempotency keys).
Schedule::command('model:prune')
    ->dailyAt('04:45')
    ->withoutOverlapping();
