<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Alert Storm Rate Limit
    |--------------------------------------------------------------------------
    |
    | Maximum number of signals that can be ingested per source_type per minute.
    | When this limit is exceeded, new signals from that source are silently
    | dropped and a warning is logged. This prevents runaway alert floods from
    | Sentry, Datadog, or PagerDuty from filling the signals table.
    |
    | Set to 0 to disable rate limiting.
    |
    */

    'storm_rate_limit' => (int) env('SIGNAL_STORM_RATE_LIMIT', 60),

    /*
    |--------------------------------------------------------------------------
    | Bug Report Structured Intake
    |--------------------------------------------------------------------------
    |
    | When enabled, every bug-report Signal triggers a Haiku call to extract
    | structured fields (steps_to_reproduce, affected_user, component, etc.)
    | into Signal->metadata.ai_extracted. Default OFF — flip per env after
    | monitoring per-team Haiku spend.
    |
    */

    'bug_report' => [
        'structured_intake_enabled' => (bool) env('BUG_REPORT_STRUCTURED_INTAKE', false),
        'structured_intake_min_chars' => (int) env('BUG_REPORT_STRUCTURED_INTAKE_MIN_CHARS', 20),

        /*
         * Bidirectional widget comments — when enabled, the bug-fix agent and the
         * external reporter exchange messages through the widget. When false, the
         * widget list endpoint returns empty and the create endpoint returns 403.
         */
        'widget_comments_enabled' => (bool) env('BUG_REPORT_WIDGET_COMMENTS', true),
    ],

];
