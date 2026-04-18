<?php

return [
    'enabled' => env('TOOL_RECOVERY_ENABLED', true),
    'retry_attempts' => 2,
    'retry_backoff_seconds' => [1, 2],
    'decompose_max_sub_steps' => 3,
];
