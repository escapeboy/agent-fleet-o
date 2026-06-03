<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Decision log (Squad borrow)
    |--------------------------------------------------------------------------
    | A crew's "decisions are the shared brain": durable, human-readable
    | decisions recorded as Memory (tier=decisions, source_type=crew_decision)
    | that future agents inherit as constraints. When enabled, the recorded
    | decisions for a crew are injected into the coordinator's decomposition
    | prompt. Off by default — recording is always allowed; only injection is
    | gated, so the prompt is byte-for-byte legacy when off.
    */
    'decision_log' => [
        'enabled' => (bool) env('CREW_DECISION_LOG_ENABLED', false),
        'max_injected' => (int) env('CREW_DECISION_LOG_MAX_INJECTED', 20),
    ],

];
