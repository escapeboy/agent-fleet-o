<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mode
    |--------------------------------------------------------------------------
    |
    | phase0 — read-only: investigate Sentry issues and send a digest only.
    |          No code changes, no PRs, no Sentry mutations.
    | phase1 — autonomous investigation: open PRs against `develop` for
    |          actionable issues. Still T4-mode — nothing is auto-merged;
    |          a human merges every PR.
    |
    */

    'mode' => env('SENTRY_WATCHDOG_MODE', 'phase0'),

    /*
    |--------------------------------------------------------------------------
    | Triage thresholds
    |--------------------------------------------------------------------------
    |
    | confidence_threshold — minimum investigation confidence (0..1) before a
    |   phase1 issue is delegated to a fixing agent. Below it: investigate-only.
    | t1_max_diff_lines / t1_max_files — upper bounds for the SentryFixTierClassifier
    |   to label a fix T1 (trivial). Label only while in T4-mode.
    |
    */

    'confidence_threshold' => (float) env('SENTRY_WATCHDOG_CONFIDENCE_THRESHOLD', 0.8),

    't1_max_diff_lines' => (int) env('SENTRY_WATCHDOG_T1_MAX_DIFF_LINES', 40),

    't1_max_files' => (int) env('SENTRY_WATCHDOG_T1_MAX_FILES', 3),

    /*
    |--------------------------------------------------------------------------
    | Batch size
    |--------------------------------------------------------------------------
    |
    | max_signals_per_run — cap on Sentry signals triaged in a single watchdog
    |   run. Each triage is a ~30s LLM call, so an uncapped run over a large
    |   backlog would exceed the job timeout. Remaining signals carry over to
    |   the next run.
    |
    */

    'max_signals_per_run' => (int) env('SENTRY_WATCHDOG_MAX_SIGNALS_PER_RUN', 15),

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | digest_channel — outbound channel for the 2x/day digest and for
    |   immediate critical-issue alerts. Supported: 'email', 'telegram'.
    |   Defaults to 'email' (uses the platform mailer — no extra setup).
    |   Switch to 'telegram' once a Telegram bot is configured for the team.
    | digest_email — explicit recipient for the email channel. When null,
    |   the digest goes to the team owner's email.
    |
    */

    'digest_channel' => env('SENTRY_WATCHDOG_DIGEST_CHANNEL', 'email'),

    'digest_email' => env('SENTRY_WATCHDOG_DIGEST_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Critical-issue alerting
    |--------------------------------------------------------------------------
    |
    | critical_immediate — when true, every critical signal in a batch fires
    |   its own Telegram/email alert the moment it is classified. When false,
    |   critical issues are rolled up into the single per-run digest under a
    |   "Critical issues" section. False by default: a noisy batch (e.g. 15
    |   critical-flagged signals) used to send 15 separate notifications, one
    |   per signal, which made the channel unusable.
    |
    */
    'critical_immediate' => (bool) env('SENTRY_WATCHDOG_CRITICAL_IMMEDIATE', false),

    /*
    |--------------------------------------------------------------------------
    | Title filters — noise suppression
    |--------------------------------------------------------------------------
    |
    | ignore_title_patterns — SQL ILIKE patterns. Signals whose Sentry issue
    |   title matches any pattern are skipped at triage time and stay out of
    |   the digest. Default suppresses "Cron failure:" boilerplate that Sentry
    |   keeps emitting from server-side monitor configs even after
    |   `->sentryMonitor()` was removed from the codebase (see Serena memory
    |   features/sentry-cron-monitor-removed-2026-05-15). These signals
    |   produce no useful triage — the LLM defaults to 0.0 confidence and
    |   they pollute the channel.
    |
    */

    'ignore_title_patterns' => [
        'Cron failure:%',
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 1 safety guards
    |--------------------------------------------------------------------------
    |
    | max_prs_per_run — hard cap on the number of phase1 delegations per
    |   watchdog run. Beyond this, eligible signals are downgraded to
    |   investigate-only with a "PR-quota-reached" marker. Conservative
    |   default of 3 keeps the human reviewer's PR queue manageable while
    |   we learn how often quality holds.
    | vps_invoke_cap_per_run — soft cap on claude-code-vps invocations
    |   per watchdog run (across all PR attempts). Anthropic Max OAuth is
    |   on FleetQ's bill, not the team's credit ledger, so a runaway agent
    |   loop could bill us silently. Each fix typically does 3-5 invocations;
    |   50 buys 10 fixes worth of slack.
    |
    */

    'max_prs_per_run' => (int) env('SENTRY_WATCHDOG_MAX_PRS_PER_RUN', 3),

    'vps_invoke_cap_per_run' => (int) env('SENTRY_WATCHDOG_VPS_INVOKE_CAP', 50),

];
