<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// New S-ryhmä receipts. Frequent on purpose: a list request is cheap, and
// regular use is what may keep the borrowed browser session alive.
// Needs the server cron to call `schedule:run` every minute (see CLAUDE.md).
Schedule::command('sgroup:sync')->everyFifteenMinutes()->withoutOverlapping();
