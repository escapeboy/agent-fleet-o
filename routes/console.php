<?php

use App\Domain\Project\Jobs\DispatchScheduledProjectsJob;
use App\Domain\Signal\Jobs\RefreshExpiringWebhooksJob;
use App\Infrastructure\AI\Jobs\ComputeProviderRankingJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('approvals:expire-stale')->hourly()->sentryMonitor('approvals-expire-stale', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('memory:audit-proposals')->hourly()->withoutOverlapping(1)->sentryMonitor('memory-audit-proposals', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('approvals:auto-approve-on-loop')->everyMinute()->withoutOverlapping(1)->sentryMonitor('approvals-auto-approve-on-loop', failureIssueThreshold: 4, checkInMargin: 5);
// Temporarily disabled to avoid rate-limiting Google Gemini during experiment runs
// Schedule::command('agents:health-check')->everyFiveMinutes();
Schedule::command('tools:health-check')->everyFiveMinutes()->withoutOverlapping(4)->sentryMonitor('tools-health-check', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('tools:refresh-definitions --stale-minutes=60')->hourly()->sentryMonitor('tools-refresh-definitions', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('bridge:health-check')->everyFiveMinutes()->withoutOverlapping(4)->sentryMonitor('bridge-health-check', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('bridge:detect-stale')->everyTwoMinutes()->withoutOverlapping(2)->sentryMonitor('bridge-detect-stale', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('metrics:aggregate --period=hourly')->hourly()->sentryMonitor('metrics-aggregate-hourly', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('metrics:aggregate --period=daily')->dailyAt('01:00')->sentryMonitor('metrics-aggregate-daily', failureIssueThreshold: 4, checkInMargin: 5);
// Prometheus gauge sampler — runs every minute (Laravel's smallest native interval).
// Counter metrics arrive via event listeners; this command keeps gauges fresh.
Schedule::command('metrics:sample')->everyMinute()->withoutOverlapping(1)->sentryMonitor('metrics-sample', failureIssueThreshold: 4, checkInMargin: 5);
// Hourly: trim the top-N ranking sorted set to bound Redis memory.
Schedule::command('metrics:sample --trim')->hourly()->sentryMonitor('metrics-sample-trim', failureIssueThreshold: 4, checkInMargin: 5);
// Platform alerting: evaluate every minute, dedup via Redis cooldown.
Schedule::command('alerts:check')->everyMinute()->withoutOverlapping(1)->sentryMonitor('alerts-check', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('connectors:poll')->everyFifteenMinutes()->sentryMonitor('connectors-poll', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('connectors:poll --driver=http_monitor')->everyFiveMinutes()->withoutOverlapping(5)->sentryMonitor('connectors-poll-http-monitor', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('connectors:poll --driver=telegram')->everyMinute()->withoutOverlapping(2)->sentryMonitor('connectors-poll-telegram', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('connectors:poll --driver=signal_protocol')->everyMinute()->withoutOverlapping(2)->sentryMonitor('connectors-poll-signal-protocol', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('connectors:poll --driver=matrix')->everyMinute()->withoutOverlapping(2)->sentryMonitor('connectors-poll-matrix', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('digest:send-weekly')->weeklyOn(1, '09:00')->sentryMonitor('digest-send-weekly', failureIssueThreshold: 4, checkInMargin: 5);

// Harvest unmatched ErrorTranslator patterns weekly so future dictionary
// expansion is data-driven. Output is appended to a dedicated log file
// for ops review and downstream pipeline processing.
Schedule::command('error-translator:harvest --format=json')
    ->weeklyOn(1, '08:30')
    ->withoutOverlapping(15)
    ->appendOutputTo(storage_path('logs/error-translator-harvest.log'))
    ->sentryMonitor('error-translator-harvest', failureIssueThreshold: 4, checkInMargin: 5);

Schedule::command('audit:cleanup')->dailyAt('02:00')->sentryMonitor('audit-cleanup', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('agent-session-events:cleanup')->dailyAt('03:45')->withoutOverlapping(60)->onOneServer()->sentryMonitor('agent-session-events-cleanup', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('memory:check-drift --notify')->dailyAt('04:15')->withoutOverlapping(60)->onOneServer()->sentryMonitor('memory-check-drift', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('worldmodel:rebuild')->dailyAt('02:15')->withoutOverlapping(60)->onOneServer()->sentryMonitor('worldmodel-rebuild', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('kg:build-communities')->dailyAt('02:45')->withoutOverlapping(60)->onOneServer()->sentryMonitor('kg-build-communities', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('kg:merge-entities')->dailyAt('04:30')->withoutOverlapping(30)->onOneServer()->sentryMonitor('kg-merge-entities', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('signals:cleanup-bug-reports')->dailyAt('03:00')->sentryMonitor('signals-cleanup-bug-reports', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('sanctum:prune-expired --hours=48')->daily()->sentryMonitor('sanctum-prune-expired', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('tasks:recover-stuck')->everyFiveMinutes()->sentryMonitor('tasks-recover-stuck', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('websites:recover-stuck')->everyFiveMinutes()->sentryMonitor('websites-recover-stuck', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('human-tasks:check-sla')->everyFiveMinutes()->sentryMonitor('human-tasks-check-sla', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('workflows:poll-time-gates')->everyMinute()->withoutOverlapping(1)->sentryMonitor('workflows-poll-time-gates', failureIssueThreshold: 4, checkInMargin: 5);

// Integration health checks, polling, token refresh, and Activepieces piece sync
Schedule::command('integrations:ping')->everyFifteenMinutes()->withoutOverlapping(10)->sentryMonitor('integrations-ping', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('integrations:poll')->everyFifteenMinutes()->withoutOverlapping(10)->sentryMonitor('integrations-poll', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('integrations:refresh-tokens')->everyFiveMinutes()->withoutOverlapping(3)->sentryMonitor('integrations-refresh-tokens', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('integrations:sync-activepieces')->hourly()->withoutOverlapping(30)->sentryMonitor('integrations-sync-activepieces', failureIssueThreshold: 4, checkInMargin: 5);

// Refresh Reddit browser session tokens (token_v2 expires every ~24h)
Schedule::command('credentials:refresh-reddit')->hourly()->withoutOverlapping(5)->sentryMonitor('credentials-refresh-reddit', failureIssueThreshold: 4, checkInMargin: 5);

// Project scheduling & budget enforcement
Schedule::command('projects:check-budgets')->hourly()->sentryMonitor('projects-check-budgets', failureIssueThreshold: 4, checkInMargin: 5);

// Relationship health scoring (daily)
Schedule::command('contacts:score-health')->dailyAt('03:30')->withoutOverlapping(60)->sentryMonitor('contacts-score-health', failureIssueThreshold: 4, checkInMargin: 5);

// Auto-skill generation from recurring experiment patterns (weekly)
Schedule::command('skills:auto-generate')->weeklyOn(0, '02:00')->withoutOverlapping(120)->sentryMonitor('skills-auto-generate', failureIssueThreshold: 4, checkInMargin: 5);

// Agent memory consolidation & pruning (consolidate BEFORE prune)
Schedule::command('memories:consolidate')->dailyAt('02:30')->withoutOverlapping(90)->onOneServer()->sentryMonitor('memories-consolidate', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('memories:prune')->dailyAt('03:00')->sentryMonitor('memories-prune', failureIssueThreshold: 4, checkInMargin: 5);
Schedule::command('memory:prune')->dailyAt('03:15')->withoutOverlapping(30)->sentryMonitor('memory-prune', failureIssueThreshold: 4, checkInMargin: 5);

// Agent feedback analysis — weekly batch generates EvolutionProposals for underperforming agents
Schedule::command('agents:analyze-feedback')->weeklyOn(1, '06:00')->sentryMonitor('agents-analyze-feedback', failureIssueThreshold: 4, checkInMargin: 5);

// GEPA skill evolution cycle — generates and evaluates system_prompt mutations for mature skills
Schedule::command('skills:evolve')->weeklyOn(0, '03:00')->withoutOverlapping(120)->sentryMonitor('skills-evolve', failureIssueThreshold: 4, checkInMargin: 5);

// Skill degradation monitor — hourly scan creates EvolutionProposals for underperforming skills
Schedule::command('skills:monitor-degradation')->hourly()->sentryMonitor('skills-monitor-degradation', failureIssueThreshold: 4, checkInMargin: 5);

// Marketplace quality aggregation — roll up installed skill metrics into listing quality scores
Schedule::command('marketplace:aggregate-quality')->everySixHours()->sentryMonitor('marketplace-aggregate-quality', failureIssueThreshold: 4, checkInMargin: 5);

// Prune growing tables: llm_request_logs (30d), semantic_cache_entries (expired+90d), assistant_messages (90d)
Schedule::command('model:prune')->dailyAt('04:00')->sentryMonitor('model-prune', failureIssueThreshold: 4, checkInMargin: 5);

// Version update check
Schedule::command('system:check-updates')->hourly()->runInBackground()->sentryMonitor('system-check-updates', failureIssueThreshold: 4, checkInMargin: 5);

// Pre-generate OpenAPI spec so /docs/api.json is served from a static file
Schedule::command('scramble:export --path=public/api.json')->weeklyOn(1, '03:30')->sentryMonitor('scramble-export', failureIssueThreshold: 4, checkInMargin: 5);

// Boruna Audit Console — nightly bundle integrity verification
if (config('boruna_audit.enabled', false)) {
    Schedule::command('boruna:verify --tenant=all --sample='.config('boruna_audit.verification.sample_size', 20))
        ->cron(config('boruna_audit.verification.schedule_cron', '0 3 * * *'))
        ->withoutOverlapping(120)
        ->runInBackground()
        ->sentryMonitor('boruna-verify', failureIssueThreshold: 4, checkInMargin: 5);
}

Schedule::command('conversations:expire')->everyFiveMinutes()->sentryMonitor('conversations-expire', failureIssueThreshold: 4, checkInMargin: 5);

Schedule::job(new DispatchScheduledProjectsJob)->everyMinute();

// Provider ranker — aggregate 24h of llm_request_logs into per-(provider, model) median
// latency + cost-per-1k-tokens. Consumed by FallbackAiGateway when AiRequestDTO::gatewaySort is set.
Schedule::job(new ComputeProviderRankingJob)->everyFiveMinutes()->withoutOverlapping(4);

// Agent heartbeats — evaluate scheduled autonomous tasks every minute
Schedule::command('agents:heartbeats')->everyMinute()->withoutOverlapping(1)->sentryMonitor('agents-heartbeats', failureIssueThreshold: 4, checkInMargin: 5);

// Clean stale per-agent serial execution locks
Schedule::command('agents:clean-locks')->everyFiveMinutes()->withoutOverlapping()->sentryMonitor('agents-clean-locks', failureIssueThreshold: 4, checkInMargin: 5);

// Refresh webhooks with expiring TTLs (e.g. Jira Cloud 30-day webhook expiry)
Schedule::job(new RefreshExpiringWebhooksJob)->weekly();
