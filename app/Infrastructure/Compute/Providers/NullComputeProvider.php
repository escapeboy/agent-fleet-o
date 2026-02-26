<?php

namespace App\Infrastructure\Compute\Providers;

use App\Infrastructure\Compute\Contracts\ComputeProviderInterface;
use App\Infrastructure\Compute\DTOs\ComputeHealthDTO;
use App\Infrastructure\Compute\DTOs\ComputeJobDTO;
use App\Infrastructure\Compute\DTOs\ComputeJobResultDTO;

/**
 * No-op compute provider for testing and local development.
 * Accepts any credentials and immediately returns a successful result.
 */
class NullComputeProvider implements ComputeProviderInterface
{
    public function runSync(ComputeJobDTO $job): ComputeJobResultDTO
    {
        return new ComputeJobResultDTO(
            status: 'completed',
            output: ['null_provider' => true, 'input' => $job->input],
        );
    }

    public function submit(ComputeJobDTO $job): string
    {
        return 'null-job-'.uniqid();
    }

    public function getResult(string $jobId, ComputeJobDTO $job): ComputeJobResultDTO
    {
        return new ComputeJobResultDTO(
            status: 'completed',
            output: ['null_provider' => true],
            jobId: $jobId,
        );
    }

    public function cancel(string $jobId, ComputeJobDTO $job): void {}

    public function health(ComputeJobDTO $job): ComputeHealthDTO
    {
        return new ComputeHealthDTO(
            healthy: true,
            workersReady: 1,
            message: 'Null provider is always healthy.',
        );
    }

    public function validateCredentials(array $credentials): bool
    {
        return true;
    }

    public function estimateCost(ComputeJobDTO $job): int
    {
        return 0;
    }
}
