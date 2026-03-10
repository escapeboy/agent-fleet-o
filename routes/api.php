<?php

use App\Http\Controllers\ClearCueWebhookController;
use App\Http\Controllers\DatadogAlertWebhookController;
use App\Http\Controllers\DiscordWebhookController;
use App\Http\Controllers\GitHubIssueWebhookController;
use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\IntegrationWebhookController;
use App\Http\Controllers\JiraWebhookController;
use App\Http\Controllers\LinearWebhookController;
use App\Http\Controllers\PagerDutyWebhookController;
use App\Http\Controllers\PerTeamSignalWebhookController;
use App\Http\Controllers\SentryAlertWebhookController;
use App\Http\Controllers\SignalWebhookController;
use App\Http\Controllers\SlackWebhookController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Per-team signal webhooks — unique URL per team per driver, secret stored in DB
// POST /api/signals/{driver}/{teamId}  (e.g. /api/signals/github/01927f3c-...)
// Must be registered BEFORE the legacy single-team routes to avoid shadowing.
Route::post('/signals/{driver}/{teamId}', PerTeamSignalWebhookController::class)
    ->name('signals.per_team')
    ->middleware('throttle:120,1')
    ->whereUuid('teamId');

// Legacy signal ingestion (single-team / self-hosted — HMAC validated in controller)
Route::post('/signals/webhook', SignalWebhookController::class)->name('signals.webhook');

// Slack Events API (HMAC-SHA256 + URL verification challenge)
Route::post('/signals/slack', SlackWebhookController::class)->name('signals.slack');

// WhatsApp webhook (verification + message ingestion)
Route::get('/signals/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('signals.whatsapp.verify');
Route::post('/signals/whatsapp', [WhatsAppWebhookController::class, 'handle'])->name('signals.whatsapp.handle');

// Discord interactions endpoint (Ed25519 validated in controller)
Route::post('/signals/discord', DiscordWebhookController::class)->name('signals.discord');

// Ticket connectors (GitHub, Jira, Linear — HMAC validated in each controller)
Route::post('/signals/github', GitHubWebhookController::class)->name('signals.github');
Route::post('/signals/github-issues', GitHubIssueWebhookController::class)->name('signals.github-issues'); // backward compat (issues only)
Route::post('/signals/jira', JiraWebhookController::class)->name('signals.jira');
Route::post('/signals/linear', LinearWebhookController::class)->name('signals.linear');

// GTM intent connectors (ClearCue — HMAC validated in controller)
Route::post('/signals/clearcue', ClearCueWebhookController::class)->name('signals.clearcue');

// Alert connectors (Sentry, Datadog, PagerDuty — validated in each controller)
Route::post('/signals/sentry', SentryAlertWebhookController::class)->name('signals.sentry');
// Datadog: preferred form uses X-Datadog-Webhook-Secret header; legacy form embeds secret in URL (deprecated)
Route::post('/signals/datadog', DatadogAlertWebhookController::class)->name('signals.datadog');
Route::post('/signals/datadog/{secret}', DatadogAlertWebhookController::class)->name('signals.datadog.legacy');
Route::post('/signals/pagerduty', PagerDutyWebhookController::class)->name('signals.pagerduty');

// Generic integration webhooks (per-slug, HMAC verified in controller)
Route::post('/integrations/webhook/{slug}', [IntegrationWebhookController::class, 'handle'])
    ->name('integrations.webhook')
    ->middleware('throttle:120,1');

// WhatsApp webhook verification challenge (GET hub.challenge)
Route::get('/integrations/webhook/{slug}', [IntegrationWebhookController::class, 'challenge'])
    ->name('integrations.webhook.challenge')
    ->middleware('throttle:30,1');

// Telegram webhook (optional push-mode alternative to polling)
Route::post('/telegram/webhook/{teamId}', [TelegramWebhookController::class, 'handle'])
    ->name('telegram.webhook')
    ->middleware('throttle:60,1');

// Tracking endpoints (public, no auth — rate-limited per IP as defence-in-depth)
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/track/click', [TrackingController::class, 'click'])->name('track.click');
    Route::get('/track/pixel', [TrackingController::class, 'pixel'])->name('track.pixel');
});
