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

    'confidence_threshold' => (float) env('SENTRY_WATCHDOG_CONFIDENCE_THRESHOLD', 0.7),

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

];
