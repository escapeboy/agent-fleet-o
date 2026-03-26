<?php

use App\Domain\Project\Jobs\DispatchScheduledProjectsJob;
use App\Domain\Signal\Jobs\RefreshExpiringWebhooksJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('approvals:expire-stale')->hourly();
// Temporarily disabled to avoid rate-limiting Google Gemini during experiment runs
// Schedule::command('agents:health-check')->everyFiveMinutes();
Schedule::command('tools:health-check')->everyFiveMinutes()->withoutOverlapping(4);
Schedule::command('bridge:health-check')->everyFiveMinutes()->withoutOverlapping(4);
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

// Integration health checks, polling, and token refresh
Schedule::command('integrations:ping')->everyFifteenMinutes()->withoutOverlapping(10);
Schedule::command('integrations:poll')->everyFifteenMinutes()->withoutOverlapping(10);
Schedule::command('integrations:refresh-tokens')->everyFiveMinutes()->withoutOverlapping(3);

// Refresh Reddit browser session tokens (token_v2 expires every ~24h)
Schedule::command('credentials:refresh-reddit')->hourly()->withoutOverlapping(5);

// Project scheduling & budget enforcement
Schedule::command('projects:check-budgets')->hourly();

// Agent memory consolidation & pruning (consolidate BEFORE prune)
Schedule::command('memories:consolidate')->dailyAt('02:30')->withoutOverlapping(90)->onOneServer();
Schedule::command('memories:prune')->dailyAt('03:00');

// Agent feedback analysis — weekly batch generates EvolutionProposals for underperforming agents
Schedule::command('agents:analyze-feedback')->weeklyOn(1, '06:00');

// Skill degradation monitor — hourly scan creates EvolutionProposals for underperforming skills
Schedule::command('skills:monitor-degradation')->hourly();

// Marketplace quality aggregation — roll up installed skill metrics into listing quality scores
Schedule::command('marketplace:aggregate-quality')->everySixHours();

// Prune growing tables: llm_request_logs (30d), semantic_cache_entries (expired+90d), assistant_messages (90d)
Schedule::command('model:prune')->dailyAt('04:00');

// Version update check
Schedule::command('system:check-updates')->hourly()->runInBackground();

// Pre-generate OpenAPI spec so /docs/api.json is served from a static file
Schedule::command('scramble:export --path=public/api.json')->weeklyOn(1, '03:30');

Schedule::job(new DispatchScheduledProjectsJob)->everyMinute();

// Agent heartbeats — evaluate scheduled autonomous tasks every minute
Schedule::command('agents:heartbeats')->everyMinute()->withoutOverlapping(1);

// Refresh webhooks with expiring TTLs (e.g. Jira Cloud 30-day webhook expiry)
Schedule::job(new RefreshExpiringWebhooksJob)->weekly();
