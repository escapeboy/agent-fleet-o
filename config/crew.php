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

    /*
    |--------------------------------------------------------------------------
    | Crash recovery (tasks:recover-stuck)
    |--------------------------------------------------------------------------
    | A crew task left in "running" by a dead queue worker (its execution still
    | "executing") gets its own ExecuteCrewTaskJob dispatched again, up to
    | max_retry_attempts times. After that the task goes back to "pending",
    | the execution is paused and the crew creator and team owner/admins are
    | notified. ExecuteCrewTaskJob can legitimately run for about 11 minutes
    | (timeout 300s × 2 tries + 30s backoff), so stuck_after_minutes must stay
    | above that.
    */
    'recovery' => [
        'enabled' => (bool) env('CREW_RECOVERY_ENABLED', true),
        'stuck_after_minutes' => (int) env('CREW_RECOVERY_STUCK_AFTER_MINUTES', 15),
        'max_retry_attempts' => (int) env('CREW_RECOVERY_MAX_RETRY_ATTEMPTS', 2),
    ],

];
