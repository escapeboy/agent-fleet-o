<?php

namespace App\Infrastructure\RunPod;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the RunPod REST and Serverless APIs.
 *
 * Pod management:   https://rest.runpod.io/v1/
 * Serverless calls: https://api.runpod.ai/v2/{endpoint_id}/
 *
 * All requests require: Authorization: Bearer {api_key}
 */
class RunPodClient
{
    private const REST_BASE = 'https://rest.runpod.io/v1';

    private const SERVERLESS_BASE = 'https://api.runpod.ai/v2';

    // -------------------------------------------------------------------------
    // Serverless endpoints
    // -------------------------------------------------------------------------

    /**
     * Run a job synchronously (waits for result, max ~90 s).
     */
    public function runSync(string $endpointId, array $input, string $apiKey, int $timeoutSeconds = 90): array
    {
        $response = Http::timeout($timeoutSeconds + 10)
            ->withToken($apiKey)
            ->post(self::SERVERLESS_BASE."/{$endpointId}/runsync", ['input' => $input]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "RunPod runsync [{$response->status()}]: ".mb_substr($response->body(), 0, 500),
            );
        }

        return $response->json();
    }

    /**
     * Submit a job asynchronously (returns job_id immediately).
     */
    public function run(string $endpointId, array $input, string $apiKey, ?string $webhookUrl = null): array
    {
        $payload = ['input' => $input];

        if ($webhookUrl) {
            $payload['webhook'] = $webhookUrl;
        }

        $response = Http::timeout(30)
            ->withToken($apiKey)
            ->post(self::SERVERLESS_BASE."/{$endpointId}/run", $payload);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "RunPod run [{$response->status()}]: ".mb_substr($response->body(), 0, 500),
            );
        }

        return $response->json();
    }

    /**
     * Retrieve the status/output of an async job.
     */
    public function getStatus(string $endpointId, string $jobId, string $apiKey): array
    {
        $response = Http::timeout(15)
            ->withToken($apiKey)
            ->get(self::SERVERLESS_BASE."/{$endpointId}/status/{$jobId}");

        if (! $response->successful()) {
            throw new \RuntimeException(
                "RunPod status [{$response->status()}]: ".mb_substr($response->body(), 0, 500),
            );
        }

        return $response->json();
    }

    /**
     * Check worker/queue health of a serverless endpoint.
     */
    public function getHealth(string $endpointId, string $apiKey): array
    {
        $response = Http::timeout(15)
            ->withToken($apiKey)
            ->get(self::SERVERLESS_BASE."/{$endpointId}/health");

        if (! $response->successful()) {
            throw new \RuntimeException(
                "RunPod health [{$response->status()}]: ".mb_substr($response->body(), 0, 500),
            );
        }

        return $response->json();
    }

    /**
     * Cancel a queued or in-progress job.
     */
    public function cancelJob(string $endpointId, string $jobId, string $apiKey): array
    {
        $response = Http::timeout(15)
            ->withToken($apiKey)
            ->post(self::SERVERLESS_BASE."/{$endpointId}/cancel/{$jobId}");

        if (! $response->successful()) {
            throw new \RuntimeException(
                "RunPod cancel [{$response->status()}]: ".mb_substr($response->body(), 0, 500),
            );
        }

        return $response->json();
    }

    // -------------------------------------------------------------------------
    // Pod (persistent GPU instance) management
    // -------------------------------------------------------------------------

    /**
     * Create a new pod.
     *
     * @param  array  $config  See POST /pods schema: imageName, gpuTypeIds, gpuCount, env, ports, etc.
     */
    public function createPod(array $config, string $apiKey): array
    {
        $response = Http::timeout(30)
            ->withToken($apiKey)
            ->post(self::REST_BASE.'/pods', $config);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "RunPod createPod [{$response->status()}]: ".mb_substr($response->body(), 0, 500),
            );
        }

        return $response->json();
    }

    /**
     * List all pods for the authenticated account.
     */
    public function listPods(string $apiKey): array
    {
        $response = Http::timeout(15)
            ->withToken($apiKey)
            ->get(self::REST_BASE.'/pods');

        if (! $response->successful()) {
            throw new \RuntimeException(
                "RunPod listPods [{$response->status()}]: ".mb_substr($response->body(), 0, 500),
            );
        }

        return $response->json();
    }

    /**
     * Get the details of a specific pod (status, runtime ports, cost/hr, etc.).
     */
    public function getPod(string $podId, string $apiKey): array
    {
        $response = Http::timeout(15)
            ->withToken($apiKey)
            ->get(self::REST_BASE."/pods/{$podId}");

        if (! $response->successful()) {
            throw new \RuntimeException(
                "RunPod getPod [{$response->status()}]: ".mb_substr($response->body(), 0, 500),
            );
        }

        return $response->json();
    }

    /**
     * Stop a running pod.
     */
    public function stopPod(string $podId, string $apiKey): array
    {
        $response = Http::timeout(15)
            ->withToken($apiKey)
            ->post(self::REST_BASE."/pods/{$podId}/stop");

        if (! $response->successful()) {
            throw new \RuntimeException(
                "RunPod stopPod [{$response->status()}]: ".mb_substr($response->body(), 0, 500),
            );
        }

        return $response->json();
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /**
     * Validate an API key by attempting a lightweight pods list request.
     */
    public function validateApiKey(string $apiKey): bool
    {
        try {
            $response = Http::timeout(10)
                ->withToken($apiKey)
                ->get(self::REST_BASE.'/pods');

            return $response->successful();
        } catch (\Throwable $e) {
            Log::debug('RunPodClient::validateApiKey failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
