<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('approvals:expire-stale')->hourly();
Schedule::command('agents:health-check')->everyFiveMinutes();
Schedule::command('metrics:aggregate --period=hourly')->hourly();
Schedule::command('metrics:aggregate --period=daily')->dailyAt('01:00');
Schedule::command('connectors:poll --driver=rss')->everyFifteenMinutes();
Schedule::command('digest:send-weekly')->weeklyOn(1, '09:00');
Schedule::command('audit:cleanup')->dailyAt('02:00');
