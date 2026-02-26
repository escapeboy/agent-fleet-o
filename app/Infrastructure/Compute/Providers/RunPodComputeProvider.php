<?php

namespace App\Infrastructure\Compute\Providers;

use App\Infrastructure\Compute\Contracts\ComputeProviderInterface;
use App\Infrastructure\Compute\DTOs\ComputeHealthDTO;
use App\Infrastructure\Compute\DTOs\ComputeJobDTO;
use App\Infrastructure\Compute\DTOs\ComputeJobResultDTO;
use App\Infrastructure\Compute\Services\ComputeCostEstimator;
use App\Infrastructure\RunPod\RunPodClient;

/**
 * RunPod compute provider — wraps the existing RunPodClient.
 *
 * Supports RunPod Serverless (endpoint) mode.
 * Pod lifecycle mode is handled separately by ExecuteRunPodPodSkillAction
 * for backward compatibility with existing RunpodPod skills.
 */
class RunPodComputeProvider implements ComputeProviderInterface
{
    public function __construct(
        private readonly RunPodClient $client,
        private readonly ComputeCostEstimator $costEstimator,
    ) {}

    public function runSync(ComputeJobDTO $job): ComputeJobResultDTO
    {
        $apiKey = $this->extractApiKey($job);
        $input = $this->applyInputMapping($job->input, $job->inputMapping);

        $startTime = hrtime(true);
        $result = $this->client->runSync($job->endpointId, $input, $apiKey, $job->timeoutSeconds);
        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return new ComputeJobResultDTO(
            status: $this->normalizeStatus($result['status'] ?? 'COMPLETED'),
            output: $this->extractOutput($result),
            jobId: $result['id'] ?? null,
            error: $result['error'] ?? null,
            durationMs: $durationMs,
        );
    }

    public function submit(ComputeJobDTO $job): string
    {
        $apiKey = $this->extractApiKey($job);
        $input = $this->applyInputMapping($job->input, $job->inputMapping);

        $submitted = $this->client->run($job->endpointId, $input, $apiKey);
        $jobId = $submitted['id'] ?? null;

        if (! $jobId) {
            throw new \RuntimeException('RunPod did not return a job ID.');
        }

        return $jobId;
    }

    public function getResult(string $jobId, ComputeJobDTO $job): ComputeJobResultDTO
    {
        $apiKey = $this->extractApiKey($job);
        $result = $this->client->getStatus($job->endpointId, $jobId, $apiKey);

        return new ComputeJobResultDTO(
            status: $this->normalizeStatus($result['status'] ?? 'UNKNOWN'),
            output: $this->extractOutput($result),
            jobId: $jobId,
            error: $result['error'] ?? null,
        );
    }

    public function cancel(string $jobId, ComputeJobDTO $job): void
    {
        $apiKey = $this->extractApiKey($job);
        $this->client->cancelJob($job->endpointId, $jobId, $apiKey);
    }

    public function health(ComputeJobDTO $job): ComputeHealthDTO
    {
        $apiKey = $job->credentials['api_key'] ?? null;

        if (! $apiKey) {
            return new ComputeHealthDTO(healthy: false, message: 'No API key configured.');
        }

        try {
            $data = $this->client->getHealth($job->endpointId, $apiKey);

            return new ComputeHealthDTO(
                healthy: true,
                workersReady: (int) ($data['workers']['ready'] ?? 0),
                workersRunning: (int) ($data['workers']['running'] ?? 0),
                jobsInQueue: (int) ($data['jobs']['inQueue'] ?? 0),
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

        return $this->client->validateApiKey($apiKey);
    }

    public function estimateCost(ComputeJobDTO $job): int
    {
        // Serverless: costs are billed directly to the user's RunPod account
        $gpuTypeId = $job->options['gpu_type_id'] ?? null;

        if (! $gpuTypeId) {
            return 0;
        }

        $estimatedMinutes = (int) ($job->options['estimated_minutes'] ?? 10);
        $interruptible = (bool) ($job->options['interruptible'] ?? false);

        return $this->costEstimator->estimatePodCost($gpuTypeId, $estimatedMinutes, $interruptible);
    }

    /**
     * Map provider-specific status strings to the canonical status vocabulary.
     */
    private function normalizeStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'COMPLETED', 'SUCCESS' => 'completed',
            'FAILED', 'FAILED_TO_START', 'ERROR' => 'failed',
            'IN_QUEUE', 'QUEUED' => 'queued',
            'IN_PROGRESS', 'RUNNING', 'STARTED' => 'running',
            'CANCELLED', 'CANCELED', 'TIMED_OUT' => 'cancelled',
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

    private function extractOutput(array $result): array
    {
        $output = $result['output'] ?? $result;

        if (is_string($output)) {
            return ['output' => $output];
        }

        return is_array($output) ? $output : ['output' => $output];
    }

    private function extractApiKey(ComputeJobDTO $job): string
    {
        $apiKey = $job->credentials['api_key'] ?? null;

        if (! $apiKey) {
            throw new \RuntimeException('RunPod API key missing from credentials.');
        }

        return $apiKey;
    }
}
