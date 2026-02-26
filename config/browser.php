<?php

return [
    /*
     * Enable the Browser Skill type.
     * Requires the browserless Docker service: docker compose --profile browser up -d
     */
    'enabled' => env('BROWSER_SKILL_ENABLED', false),

    /*
     * Browserless service URL.
     * When running in Docker: http://browserless:3000
     * When running locally: http://localhost:3000
     */
    'url' => env('BROWSERLESS_URL', 'http://browserless:3000'),

    /*
     * Browserless API token. Set TOKEN in the browserless container to the same value.
     */
    'token' => env('BROWSERLESS_TOKEN', null),

    /*
     * HTTP timeout in seconds for browser requests.
     * Screenshots and scrapes are usually fast; PDF generation may take longer.
     */
    'timeout' => env('BROWSERLESS_TIMEOUT', 30),
];
