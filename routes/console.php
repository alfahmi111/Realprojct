<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly reconciliation and monthly revenue recognition (REQ-0434, REQ-0432).
\Illuminate\Support\Facades\Schedule::command('finance:nightly')->dailyAt('02:00');
