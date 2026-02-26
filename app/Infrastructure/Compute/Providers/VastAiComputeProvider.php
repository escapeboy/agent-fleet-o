<?php

namespace App\Infrastructure\Compute\Providers;

use App\Infrastructure\Compute\Contracts\ComputeProviderInterface;
use App\Infrastructure\Compute\DTOs\ComputeHealthDTO;
use App\Infrastructure\Compute\DTOs\ComputeJobDTO;
use App\Infrastructure\Compute\DTOs\ComputeJobResultDTO;
use Illuminate\Support\Facades\Http;

/**
 * Vast.ai compute provider — serverless routing mode.
 *
 * Uses the two-step Vast.ai serverless API:
 *   1. POST https://run.vast.ai/route/ → obtain a live worker URL
 *   2. POST {workerUrl}/{route_path}   → send the inference payload
 *
 * The endpointId field is the Vast.ai endpoint name (e.g. "my-llm-endpoint").
 *
 * Skill configuration extras (via options / config):
 *   - route_path  (string, default '/') path to append to the worker URL
 *   - max_cost    (float, default 0.001) max $/token bid for route selection
 *
 * Note: Vast.ai serverless is synchronous — each request goes to a live
 * worker that responds directly. Async submission (submit/getResult) is not
 * supported. Always set use_sync: true in skill configuration.
 *
 * Auth: Authorization: Bearer {api_key}
 */
class VastAiComputeProvider implements ComputeProviderInterface
{
    private const ROUTE_URL = 'https://run.vast.ai/route/';

    private const MANAGEMENT_BASE = 'https://console.vast.ai/api/v0';

    public function runSync(ComputeJobDTO $job): ComputeJobResultDTO
    {
        $apiKey = $this->extractApiKey($job);
        $routePath = ltrim($job->options['route_path'] ?? '/', '/');
        $maxCost = (float) ($job->options['max_cost'] ?? 0.001);

        $startTime = hrtime(true);

        // Step 1: Route to a live worker
        $routeResp = Http::timeout(30)
            ->withToken($apiKey)
            ->post(self::ROUTE_URL, [
                'endpoint' => $job->endpointId,
                'cost' => $maxCost,
            ]);

        if (! $routeResp->successful()) {
            throw new \RuntimeException(
                "Vast.ai route [{$routeResp->status()}]: ".mb_substr($routeResp->body(), 0, 500),
            );
        }

        $workerUrl = $routeResp->json('url');

        if (! $workerUrl) {
            throw new \RuntimeException('Vast.ai route did not return a worker URL.');
        }

        // Step 2: Send inference payload to the worker
        $targetUrl = rtrim($workerUrl, '/').'/'.$routePath;
        $input = $this->applyInputMapping($job->input, $job->inputMapping);

        $inferResp = Http::timeout($job->timeoutSeconds)
            ->withToken($apiKey)
            ->post($targetUrl, $input);

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        if (! $inferResp->successful()) {
            throw new \RuntimeException(
                "Vast.ai worker [{$inferResp->status()}]: ".mb_substr($inferResp->body(), 0, 500),
            );
        }

        $body = $inferResp->json();

        return new ComputeJobResultDTO(
            status: 'completed',
            output: is_array($body) ? $body : ['output' => $body],
            durationMs: $durationMs,
        );
    }

    /**
     * Vast.ai serverless is synchronous — async submission is not supported.
     * Always configure GpuCompute skills with use_sync: true when using Vast.ai.
     */
    public function submit(ComputeJobDTO $job): string
    {
        throw new \RuntimeException(
            'Vast.ai serverless does not support async job submission. '
            .'Set use_sync: true in the skill configuration.',
        );
    }

    public function getResult(string $jobId, ComputeJobDTO $job): ComputeJobResultDTO
    {
        throw new \RuntimeException(
            'Vast.ai serverless does not support async getResult. '
            .'Set use_sync: true in the skill configuration.',
        );
    }

    public function cancel(string $jobId, ComputeJobDTO $job): void
    {
        // Vast.ai serverless requests are synchronous — nothing to cancel.
    }

    public function health(ComputeJobDTO $job): ComputeHealthDTO
    {
        $apiKey = $job->credentials['api_key'] ?? null;

        if (! $apiKey) {
            return new ComputeHealthDTO(healthy: false, message: 'No API key configured.');
        }

        try {
            // List endpoints as a lightweight health/auth check
            $response = Http::timeout(10)
                ->withToken($apiKey)
                ->get(self::MANAGEMENT_BASE.'/endptjobs/');

            return new ComputeHealthDTO(
                healthy: $response->successful(),
                message: $response->successful() ? null : "HTTP {$response->status()}",
            );
        } catch (\Throwable $e) {
            return new ComputeHealthDTO(healthy: false, message: $e->getMessage());
        }
    }

    public function validateCredentials(array $credentials): bool
    {
        $apiKey = $credentials['api_key'] ?? null;

        if (! $apiKey) {
            return false;
        }

        try {
            $response = Http::timeout(10)
                ->withToken($apiKey)
                ->get(self::MANAGEMENT_BASE.'/endptjobs/');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function estimateCost(ComputeJobDTO $job): int
    {
        // Billed directly to the user's Vast.ai account
        return 0;
    }

    private function applyInputMapping(array $input, array $mapping): array
    {
        if (empty($mapping)) {
            return $input;
        }

        $mapped = [];
        $consumed = [];

        foreach ($mapping as $endpointKey => $inputKey) {
            if (array_key_exists($inputKey, $input)) {
                $mapped[$endpointKey] = $input[$inputKey];
                $consumed[] = $inputKey;
            }
        }

        foreach ($input as $key => $value) {
            if (! in_array($key, $consumed, true)) {
                $mapped[$key] = $value;
            }
        }

        return $mapped;
    }

    private function extractApiKey(ComputeJobDTO $job): string
    {
        $apiKey = $job->credentials['api_key'] ?? null;

        if (! $apiKey) {
            throw new \RuntimeException('Vast.ai API key missing from credentials.');
        }

        return $apiKey;
    }
}
