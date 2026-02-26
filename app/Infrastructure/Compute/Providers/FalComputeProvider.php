<?php

namespace App\Infrastructure\Compute\Providers;

use App\Infrastructure\Compute\Contracts\ComputeProviderInterface;
use App\Infrastructure\Compute\DTOs\ComputeHealthDTO;
use App\Infrastructure\Compute\DTOs\ComputeJobDTO;
use App\Infrastructure\Compute\DTOs\ComputeJobResultDTO;
use Illuminate\Support\Facades\Http;

/**
 * Fal.ai compute provider — runs inference via the Fal queue and sync APIs.
 *
 * Queue (async): https://queue.fal.run/{model_id}
 * Sync:          https://fal.run/{model_id}
 * Auth:          Authorization: Key {fal_key}  (NOT Bearer!)
 *
 * The endpointId field is the model path, e.g. "fal-ai/flux/dev".
 *
 * Result field naming: Fal uses "response" (not "output") for the result payload.
 */
class FalComputeProvider implements ComputeProviderInterface
{
    private const QUEUE_BASE = 'https://queue.fal.run';

    private const SYNC_BASE = 'https://fal.run';

    public function runSync(ComputeJobDTO $job): ComputeJobResultDTO
    {
        $apiKey = $this->extractApiKey($job);
        $input = $this->applyInputMapping($job->input, $job->inputMapping);

        $startTime = hrtime(true);

        $response = Http::timeout($job->timeoutSeconds + 15)
            ->withHeaders(['Authorization' => "Key {$apiKey}"])
            ->post(self::SYNC_BASE."/{$job->endpointId}", $input);

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Fal.ai sync [{$response->status()}]: ".mb_substr($response->body(), 0, 500),
            );
        }

        $body = $response->json();

        return new ComputeJobResultDTO(
            status: 'completed',
            output: is_array($body) ? $body : ['output' => $body],
            durationMs: $durationMs,
        );
    }

    public function submit(ComputeJobDTO $job): string
    {
        $apiKey = $this->extractApiKey($job);
        $input = $this->applyInputMapping($job->input, $job->inputMapping);

        $url = self::QUEUE_BASE."/{$job->endpointId}";

        $response = Http::timeout(30)
            ->withHeaders(['Authorization' => "Key {$apiKey}"])
            ->post($url, $input);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Fal.ai submit [{$response->status()}]: ".mb_substr($response->body(), 0, 500),
            );
        }

        $data = $response->json();
        $requestId = $data['request_id'] ?? null;

        if (! $requestId) {
            throw new \RuntimeException('Fal.ai did not return a request_id.');
        }

        return $requestId;
    }

    public function getResult(string $jobId, ComputeJobDTO $job): ComputeJobResultDTO
    {
        $apiKey = $this->extractApiKey($job);

        // Check status first
        $statusUrl = self::QUEUE_BASE."/{$job->endpointId}/requests/{$jobId}/status";

        $statusResp = Http::timeout(15)
            ->withHeaders(['Authorization' => "Key {$apiKey}"])
            ->get($statusUrl);

        if (! $statusResp->successful()) {
            throw new \RuntimeException(
                "Fal.ai status [{$statusResp->status()}]: ".mb_substr($statusResp->body(), 0, 500),
            );
        }

        $statusData = $statusResp->json();
        $falStatus = $statusData['status'] ?? 'IN_QUEUE';
        $canonicalStatus = $this->normalizeStatus($falStatus);

        if ($canonicalStatus !== 'completed') {
            return new ComputeJobResultDTO(
                status: $canonicalStatus,
                jobId: $jobId,
            );
        }

        // Fetch the full result when completed
        $resultUrl = self::QUEUE_BASE."/{$job->endpointId}/requests/{$jobId}";

        $resultResp = Http::timeout(15)
            ->withHeaders(['Authorization' => "Key {$apiKey}"])
            ->get($resultUrl);

        if (! $resultResp->successful()) {
            throw new \RuntimeException(
                "Fal.ai getResult [{$resultResp->status()}]: ".mb_substr($resultResp->body(), 0, 500),
            );
        }

        $result = $resultResp->json();

        // Fal uses "response" as the result field, not "output"
        $output = $result['response'] ?? $result;

        return new ComputeJobResultDTO(
            status: 'completed',
            output: is_array($output) ? $output : ['output' => $output],
            jobId: $jobId,
        );
    }

    public function cancel(string $jobId, ComputeJobDTO $job): void
    {
        $apiKey = $this->extractApiKey($job);
        $cancelUrl = self::QUEUE_BASE."/{$job->endpointId}/requests/{$jobId}/cancel";

        Http::timeout(15)
            ->withHeaders(['Authorization' => "Key {$apiKey}"])
            ->put($cancelUrl); // Fal uses PUT, not POST, for cancellation
    }

    public function health(ComputeJobDTO $job): ComputeHealthDTO
    {
        // Fal has no dedicated health endpoint — report healthy if credentials are valid
        $apiKey = $job->credentials['api_key'] ?? null;

        if (! $apiKey) {
            return new ComputeHealthDTO(healthy: false, message: 'No API key configured.');
        }

        return new ComputeHealthDTO(
            healthy: true,
            message: 'Fal.ai credentials present (no health endpoint available).',
        );
    }

    public function validateCredentials(array $credentials): bool
    {
        $apiKey = $credentials['api_key'] ?? null;

        // Fal API keys follow the format "key-id:key-secret" or similar
        // There is no lightweight validation endpoint without queuing a real job
        return ! empty($apiKey);
    }

    public function estimateCost(ComputeJobDTO $job): int
    {
        // Billed directly to user's Fal.ai account
        return 0;
    }

    private function normalizeStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'COMPLETED' => 'completed',
            'IN_PROGRESS' => 'running',
            'IN_QUEUE' => 'queued',
            'CANCELLATION_REQUESTED', 'CANCELLED', 'CANCELED' => 'cancelled',
            'ERROR', 'FAILED' => 'failed',
            default => 'queued',
        };
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
            throw new \RuntimeException('Fal.ai API key missing from credentials.');
        }

        return $apiKey;
    }
}
