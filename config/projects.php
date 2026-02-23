<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scheduling Configuration
    |--------------------------------------------------------------------------
    */
    'scheduling' => [
        'max_catchup_count' => (int) env('PROJECT_MAX_CATCHUP_COUNT', 3),
        'catchup_delay_seconds' => (int) env('PROJECT_CATCHUP_DELAY_SECONDS', 30),
    ],

];
