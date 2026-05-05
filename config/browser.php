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

    /*
     * Browser Harness (build #4 of Trendshift sprint, activated in close-all-out-of-scope).
     *
     * When true, BrowserHarnessHandler will exec `chromium-browser --headless ...`
     * inside the sandbox via DockerSandboxExecutor. Requires the sandbox image
     * to include the chromium package (added in this sprint to docker/sandbox/Dockerfile).
     *
     * Disabled by default — turning on without rebuilding the sandbox image
     * will return a "browser harness is disabled" error from the handler.
     */
    'harness_enabled' => env('BROWSER_HARNESS_ENABLED', false),
];
