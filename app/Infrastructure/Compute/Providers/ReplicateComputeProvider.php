<?php

namespace App\Infrastructure\Compute\Providers;

use App\Infrastructure\Compute\Contracts\ComputeProviderInterface;
use App\Infrastructure\Compute\DTOs\ComputeHealthDTO;
use App\Infrastructure\Compute\DTOs\ComputeJobDTO;
use App\Infrastructure\Compute\DTOs\ComputeJobResultDTO;
use Illuminate\Support\Facades\Http;

/**
 * Replicate compute provider — runs predictions via the Replicate HTTP API.
 *
 * Endpoint: https://api.replicate.com/v1/
 * Auth:     Authorization: Bearer {api_token}
 *
 * The endpointId field accepts:
 *   - "owner/model-name:version_id"  (versioned model)
 *   - "owner/model-name"             (latest version of a public model)
 *
 * Sync mode uses the "Prefer: wait=N" header (1–60 s). If the prediction
 * does not finish within N seconds, polling is performed automatically.
 */
class ReplicateComputeProvider implements ComputeProviderInterface
{
    private const BASE_URL = 'https://api.replicate.com/v1';

    public function runSync(ComputeJobDTO $job): ComputeJobResultDTO
    {
        $apiKey = $this->extractApiKey($job);
        $waitSeconds = min((int) ($job->options['wait_seconds'] ?? 60), 60);

        $startTime = hrtime(true);

        $response = Http::timeout($waitSeconds + 15)
            ->withToken($apiKey)
            ->withHeaders(['Prefer' => "wait={$waitSeconds}"])
            ->post(self::BASE_URL.'/predictions', $this->buildPayload($job));

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Replicate createPrediction [{$response->status()}]: ".mb_substr($response->body(), 0, 500)
            );
        }

        $prediction = $response->json();
        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
        $status = $this->normalizeStatus($prediction['status'] ?? 'starting');

        // Prediction completed synchronously within the wait window
        if ($status !== 'queued' && $status !== 'running') {
            return $this->toDTOFromPrediction($prediction, $durationMs);
        }

        // Did not finish in time — fall back to polling
        $predictionId = $prediction['id'] ?? null;

        if (! $predictionId) {
            throw new \RuntimeException('Replicate did not return a prediction ID.');
        }

        return $this->pollUntilTerminal($predictionId, $apiKey, $job->timeoutSeconds, $startTime);
    }

    public function submit(ComputeJobDTO $job): string
    {
        $apiKey = $this->extractApiKey($job);

        $response = Http::timeout(30)
            ->withToken($apiKey)
            ->post(self::BASE_URL.'/predictions', $this->buildPayload($job));

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Replicate submit [{$response->status()}]: ".mb_substr($response->body(), 0, 500)
            );
        }

        $prediction = $response->json();
        $predictionId = $prediction['id'] ?? null;

        if (! $predictionId) {
            throw new \RuntimeException('Replicate did not return a prediction ID.');
        }

        return $predictionId;
    }

    public function getResult(string $jobId, ComputeJobDTO $job): ComputeJobResultDTO
    {
        $apiKey = $this->extractApiKey($job);

        $response = Http::timeout(15)
            ->withToken($apiKey)
            ->get(self::BASE_URL."/predictions/{$jobId}");

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Replicate getResult [{$response->status()}]: ".mb_substr($response->body(), 0, 500)
            );
        }

        return $this->toDTOFromPrediction($response->json());
    }

    public function cancel(string $jobId, ComputeJobDTO $job): void
    {
        $apiKey = $this->extractApiKey($job);

        Http::timeout(15)
            ->withToken($apiKey)
            ->post(self::BASE_URL."/predictions/{$jobId}/cancel");
    }

    public function health(ComputeJobDTO $job): ComputeHealthDTO
    {
        $apiKey = $job->credentials['api_key'] ?? null;

        if (! $apiKey) {
            return new ComputeHealthDTO(healthy: false, message: 'No API key configured.');
        }

        try {
            $response = Http::timeout(10)
                ->withToken($apiKey)
                ->get(self::BASE_URL.'/account');

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
                ->get(self::BASE_URL.'/account');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function estimateCost(ComputeJobDTO $job): int
    {
        // Billed directly to user's Replicate account
        return 0;
    }

    private function buildPayload(ComputeJobDTO $job): array
    {
        $input = $this->applyInputMapping($job->input, $job->inputMapping);

        return [
            'version' => $job->endpointId,
            'input' => $input,
        ];
    }

    private function pollUntilTerminal(
        string $predictionId,
        string $apiKey,
        int $timeoutSeconds,
        int $startTime,
    ): ComputeJobResultDTO {
        $deadline = time() + $timeoutSeconds;
        $delay = 2;

        while (time() < $deadline) {
            sleep($delay);
            $delay = min($delay * 2, 10);

            $response = Http::timeout(15)
                ->withToken($apiKey)
                ->get(self::BASE_URL."/predictions/{$predictionId}");

            if (! $response->successful()) {
                continue;
            }

            $prediction = $response->json();
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            if ($this->isTerminalStatus($prediction['status'] ?? '')) {
                return $this->toDTOFromPrediction($prediction, $durationMs);
            }
        }

        Http::timeout(5)
            ->withToken($apiKey)
            ->post(self::BASE_URL."/predictions/{$predictionId}/cancel");

        throw new \RuntimeException("Replicate prediction {$predictionId} timed out after {$timeoutSeconds}s.");
    }

    private function toDTOFromPrediction(array $prediction, int $durationMs = 0): ComputeJobResultDTO
    {
        $status = $this->normalizeStatus($prediction['status'] ?? 'starting');
        $rawOutput = $prediction['output'] ?? null;

        $output = match (true) {
            is_array($rawOutput) => $rawOutput,
            is_string($rawOutput) => ['output' => $rawOutput],
            default => [],
        };

        return new ComputeJobResultDTO(
            status: $status,
            output: $output,
            jobId: $prediction['id'] ?? null,
            error: $prediction['error'] ?? null,
            durationMs: $durationMs,
        );
    }

    private function normalizeStatus(string $status): string
    {
        return match (strtolower($status)) {
            'succeeded', 'success' => 'completed',
            'failed', 'error' => 'failed',
            'starting' => 'queued',
            'processing' => 'running',
            'canceled', 'cancelled' => 'cancelled',
            default => 'queued',
        };
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array(strtolower($status), ['succeeded', 'failed', 'canceled'], true);
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
            throw new \RuntimeException('Replicate API key missing from credentials.');
        }

        return $apiKey;
    }
}
