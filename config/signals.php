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

];
