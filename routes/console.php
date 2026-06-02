<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily scan: every day at 8:00 AM UTC (market open)
Schedule::command('markets:daily')->dailyAt('08:00')->withoutOverlapping();

// Weekly scan: every Monday at 00:01 AM UTC (start of new weekly candle)
Schedule::command('markets:weekly')->weeklyOn(1, '00:01')->withoutOverlapping();

// Monthly scan: first day of each month at 00:05 AM UTC
Schedule::command('markets:monthly')->monthlyOn(1, '00:05')->withoutOverlapping();
