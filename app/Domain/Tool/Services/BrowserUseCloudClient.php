<?php

namespace App\Domain\Tool\Services;

use App\Domain\Tool\Exceptions\BrowserTaskFailedException;
use App\Domain\Tool\Exceptions\BrowserTaskTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the browser-use Cloud REST API.
 *
 * Submits autonomous browser tasks to https://api.browser-use.com/api/v2
 * and polls until the task completes, fails, or times out.
 *
 * @see https://docs.browser-use.com/cloud/api-reference
 */
class BrowserUseCloudClient
{
    private const BASE_URL = 'https://api.browser-use.com/api/v2';
    private const POLL_INTERVAL_SECONDS = 2;
    private const TERMINAL_STATUSES = ['completed', 'failed', 'stopped', 'timed_out'];

    private readonly string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? config('agent.browser_use_cloud_api_key', '');
    }

    /**
     * Submit a browser task and poll until it completes.
     *
     * @param  string  $task  Natural language task description
     * @param  array{max_steps?: int, allowed_domains?: string[], start_url?: string, llm?: string}  $options
     * @return array{status: string, output: string, steps_taken: int, duration_ms: int, screenshots: string[], urls_visited: string[], error: string|null}
     *
     * @throws BrowserTaskTimeoutException
     * @throws BrowserTaskFailedException
     */
    public function run(string $task, array $options = []): array
    {
        $timeoutSeconds = $options['timeout_seconds'] ?? 120;
        $startedAt = microtime(true);

        $taskId = $this->createTask($task, $options);

        $maxPolls = (int) ceil($timeoutSeconds / self::POLL_INTERVAL_SECONDS);

        for ($i = 0; $i < $maxPolls; $i++) {
            sleep(self::POLL_INTERVAL_SECONDS);

            $taskData = $this->getTask($taskId);
            $status = $taskData['status'] ?? 'unknown';

            if (in_array($status, self::TERMINAL_STATUSES, true)) {
                $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

                if ($status === 'failed' || $status === 'stopped') {
                    throw new BrowserTaskFailedException(
                        $taskData['error'] ?? "Browser task ended with status: {$status}"
                    );
                }

                if ($status === 'timed_out') {
                    throw new BrowserTaskTimeoutException($timeoutSeconds);
                }

                return [
                    'status'       => $status,
                    'output'       => $taskData['output'] ?? '',
                    'steps_taken'  => $taskData['steps'] ?? 0,
                    'duration_ms'  => $durationMs,
                    'screenshots'  => $taskData['screenshots'] ?? [],
                    'urls_visited' => $taskData['urls'] ?? [],
                    'error'        => null,
                ];
            }
        }

        // PHP-side timeout: task didn't finish within our polling window
        throw new BrowserTaskTimeoutException($timeoutSeconds);
    }

    /**
     * Check that the API key is valid by hitting the billing endpoint.
     */
    public function ping(): bool
    {
        try {
            $response = $this->client()->get('/billing/account');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function createTask(string $task, array $options): string
    {
        $payload = ['task' => $task];

        if (isset($options['max_steps'])) {
            $payload['maxSteps'] = $options['max_steps'];
        }

        if (! empty($options['start_url'])) {
            $payload['startUrl'] = $options['start_url'];
        }

        if (! empty($options['llm'])) {
            $payload['llm'] = $options['llm'];
        }

        try {
            $response = $this->client()->post('/tasks', $payload);

            if (! $response->successful()) {
                throw new BrowserTaskFailedException(
                    "browser-use API error {$response->status()}: {$response->body()}"
                );
            }

            $id = $response->json('id');
            if (! $id) {
                throw new BrowserTaskFailedException('browser-use API returned no task ID');
            }

            return $id;
        } catch (ConnectionException $e) {
            throw new BrowserTaskFailedException('browser-use Cloud API is unreachable: '.$e->getMessage());
        }
    }

    private function getTask(string $taskId): array
    {
        try {
            $response = $this->client()->get("/tasks/{$taskId}");

            if (! $response->successful()) {
                return ['status' => 'unknown'];
            }

            return $response->json() ?? [];
        } catch (ConnectionException) {
            return ['status' => 'unknown'];
        }
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withHeader('X-Browser-Use-API-Key', $this->apiKey)
            ->timeout(15);
    }
}
