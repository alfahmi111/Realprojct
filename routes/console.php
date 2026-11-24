<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly reconciliation and monthly revenue recognition (REQ-0434, REQ-0432).
\Illuminate\Support\Facades\Schedule::command('finance:nightly')->dailyAt('02:00')->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('notifications:run')->everyFiveMinutes()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('notifications:run --daily')->dailyAt('04:00')->withoutOverlapping();   // 07:00 Riyadh: reminders and the morning digest (Q10)
\Illuminate\Support\Facades\Schedule::command('data:retention')->dailyAt('01:00')->withoutOverlapping();   // 04:00 Riyadh: each office's retention periods (REQ-0371)
