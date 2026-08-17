<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Needs a cron entry on the server to run at all:
|
|   * * * * * cd /path/to/api && php artisan schedule:run >> /dev/null 2>&1
|
*/

// Enforces the retention policy. A policy that lives only in a privacy notice
// is not a policy; this is the thing that actually deletes the data.
Schedule::command('signdesk:purge-evidence')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onOneServer();

// Recomputes every audit chain and exits non-zero on a break. A hash chain
// only helps if something checks it — otherwise an altered row sits there
// looking exactly like a genuine one until somebody happens to look.
Schedule::command('signdesk:verify-audit')
    ->dailyAt('03:45')
    ->withoutOverlapping()
    ->onOneServer();
