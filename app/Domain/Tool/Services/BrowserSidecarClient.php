<?php

namespace App\Domain\Tool\Services;

use App\Domain\Tool\Exceptions\BrowserTaskFailedException;

/**
 * HTTP client for the self-hosted browser-use Python sidecar container.
 *
 * The sidecar exposes a thin FastAPI wrapper around browser-use Agent.run().
 * It follows the same HTTP interface pattern as BashSidecarClient.
 *
 * NOTE: This is a stub. Full implementation is in Phase 2 of the browser-use
 * integration (sidecar Docker container + full BrowserSidecarClient).
 *
 * @see plans/feat-browser-use-integration.md — Phase 2
 */
class BrowserSidecarClient
{
    /**
     * Run a browser task via the sidecar container.
     *
     * @param  array{max_steps?: int, timeout_seconds?: int, allowed_domains?: string[], start_url?: string}  $options
     * @return array{status: string, output: string, steps_taken: int, duration_ms: int, screenshots: string[], urls_visited: string[], error: string|null}
     */
    public function run(string $task, array $options = []): array
    {
        throw new BrowserTaskFailedException(
            'Browser sidecar mode is not yet available. Set AGENT_BROWSER_SANDBOX_MODE=cloud to use browser-use Cloud instead.'
        );
    }

    /**
     * Ping the sidecar health endpoint.
     */
    public function ping(): bool
    {
        return false;
    }
}
