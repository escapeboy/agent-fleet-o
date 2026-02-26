<?php

use App\Domain\Project\Jobs\DispatchScheduledProjectsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('approvals:expire-stale')->hourly();
// Temporarily disabled to avoid rate-limiting Google Gemini during experiment runs
// Schedule::command('agents:health-check')->everyFiveMinutes();
Schedule::command('metrics:aggregate --period=hourly')->hourly();
Schedule::command('metrics:aggregate --period=daily')->dailyAt('01:00');
Schedule::command('connectors:poll')->everyFifteenMinutes();
Schedule::command('connectors:poll --driver=http_monitor')->everyFiveMinutes()->withoutOverlapping(5);
Schedule::command('connectors:poll --driver=telegram')->everyMinute()->withoutOverlapping(2);
Schedule::command('connectors:poll --driver=signal_protocol')->everyMinute()->withoutOverlapping(2);
Schedule::command('connectors:poll --driver=matrix')->everyMinute()->withoutOverlapping(2);
Schedule::command('digest:send-weekly')->weeklyOn(1, '09:00');
Schedule::command('audit:cleanup')->dailyAt('02:00');
Schedule::command('sanctum:prune-expired --hours=48')->daily();
Schedule::command('tasks:recover-stuck')->everyFiveMinutes();
Schedule::command('human-tasks:check-sla')->everyFiveMinutes();
Schedule::command('workflows:poll-time-gates')->everyMinute()->withoutOverlapping(1);

// Project scheduling & budget enforcement
Schedule::command('projects:check-budgets')->hourly();

// Agent memory pruning
Schedule::command('memories:prune')->dailyAt('03:00');

Schedule::job(new DispatchScheduledProjectsJob)->everyMinute();
