<?php

namespace App\Domain\Tool\Services;

use App\Domain\Tool\Exceptions\BrowserTaskFailedException;
use App\Domain\Tool\Exceptions\BrowserTaskTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the self-hosted browser-use Python sidecar container.
 *
 * The sidecar exposes a thin FastAPI wrapper around browser-use Agent.run(),
 * following the same interface pattern as BashSidecarClient.
 *
 * @see sidecar-browser/main.py
 */
class BrowserSidecarClient
{
    private readonly string $baseUrl;

    private readonly string $secret;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('agent.browser_sidecar_url', 'http://browser_sidecar:8090'), '/');
        $this->secret = config('agent.browser_sidecar_secret', '');
    }

    /**
     * Run a browser task via the sidecar container.
     *
     * @param  array{max_steps?: int, timeout_seconds?: int, allowed_domains?: string[], start_url?: string, llm_api_key?: string, llm_provider?: string}  $options
     * @return array{status: string, output: string, steps_taken: int, duration_ms: int, screenshots: string[], urls_visited: string[], error: string|null}
     *
     * @throws BrowserTaskTimeoutException
     * @throws BrowserTaskFailedException
     */
    public function run(string $task, array $options = []): array
    {
        $timeoutSeconds = $options['timeout_seconds'] ?? 120;
        // HTTP timeout must exceed the task timeout so the response has time to arrive.
        $httpTimeout = $timeoutSeconds + 30;

        $payload = ['task' => $task];

        if (isset($options['max_steps'])) {
            $payload['max_steps'] = (int) $options['max_steps'];
        }

        if (isset($options['timeout_seconds'])) {
            $payload['timeout_seconds'] = (int) $options['timeout_seconds'];
        }

        if (! empty($options['allowed_domains'])) {
            $payload['allowed_domains'] = $options['allowed_domains'];
        }

        if (! empty($options['start_url'])) {
            $payload['start_url'] = $options['start_url'];
        }

        if (! empty($options['llm_api_key'])) {
            $payload['llm_api_key'] = $options['llm_api_key'];
        }

        if (! empty($options['llm_provider'])) {
            $payload['llm_provider'] = $options['llm_provider'];
        }

        if (! empty($options['llm_model'])) {
            $payload['llm_model'] = $options['llm_model'];
        }

        if (! empty($options['proxy_url'])) {
            $payload['proxy_url'] = $options['proxy_url'];
        }

        try {
            $response = Http::timeout($httpTimeout)
                ->baseUrl($this->baseUrl)
                ->withToken($this->secret)
                ->post('/run', $payload);

            if (! $response->successful()) {
                throw new BrowserTaskFailedException(
                    "Browser sidecar error: {$response->status()}",
                );
            }

            $body = $response->json();
            $status = $body['status'] ?? 'failed';

            if ($status === 'timed_out') {
                throw new BrowserTaskTimeoutException($timeoutSeconds);
            }

            if ($status === 'failed') {
                throw new BrowserTaskFailedException($body['error'] ?? 'Browser task failed');
            }

            return [
                'status' => $status,
                'output' => $body['output'] ?? '',
                'steps_taken' => $body['steps_taken'] ?? 0,
                'duration_ms' => $body['duration_ms'] ?? 0,
                'screenshots' => $body['screenshots'] ?? [],
                'urls_visited' => $body['urls_visited'] ?? [],
                'error' => null,
            ];
        } catch (ConnectionException $e) {
            throw new BrowserTaskFailedException(
                'Browser sidecar is unavailable. Please try again later.',
            );
        }
    }

    /**
     * Ping the sidecar health endpoint.
     */
    public function ping(): bool
    {
        try {
            $response = Http::timeout(3)->get("{$this->baseUrl}/health");

            return $response->ok() && ($response->json('ok') === true);
        } catch (\Throwable) {
            return false;
        }
    }
}
