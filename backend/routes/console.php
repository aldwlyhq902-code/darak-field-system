<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
| Requires one cron entry on the server:
|   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
| See DEPLOYMENT.md.
*/

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

// Nightly backup, verified immediately after creation. RPO is 24 hours, so a daily
// run is the contract — not a nice-to-have.
Schedule::command('darak:backup')
    ->dailyAt('02:30')
    ->withoutOverlapping();
