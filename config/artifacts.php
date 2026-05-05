<?php

return [
    'max_concurrent_downloads' => env('ARTIFACT_MAX_CONCURRENT_DOWNLOADS', 25),
    'download_slot_ttl' => 60,
];
