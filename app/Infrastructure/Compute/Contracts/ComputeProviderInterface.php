<?php

namespace App\Infrastructure\Compute\Contracts;

use App\Infrastructure\Compute\DTOs\ComputeHealthDTO;
use App\Infrastructure\Compute\DTOs\ComputeJobDTO;
use App\Infrastructure\Compute\DTOs\ComputeJobResultDTO;

/**
 * Unified interface for GPU/serverless compute providers.
 *
 * Supported providers: RunPod, Replicate, Fal.ai, Modal, Vast.ai
 *
 * All credential resolution happens before this interface is called.
 * Credentials are passed via ComputeJobDTO and must never be serialized to a queue.
 */
interface ComputeProviderInterface
{
    /**
     * Run a job synchronously — blocks until done or timeout.
     */
    public function runSync(ComputeJobDTO $job): ComputeJobResultDTO;

    /**
     * Submit a job asynchronously — returns immediately with a provider job ID.
     */
    public function submit(ComputeJobDTO $job): string;

    /**
     * Retrieve the current status / output of an async job.
     */
    public function getResult(string $jobId, ComputeJobDTO $job): ComputeJobResultDTO;

    /**
     * Cancel a queued or running job.
     */
    public function cancel(string $jobId, ComputeJobDTO $job): void;

    /**
     * Check the health and availability of the endpoint.
     */
    public function health(ComputeJobDTO $job): ComputeHealthDTO;

    /**
     * Validate provider credentials (lightweight check — e.g. test API call).
     */
    public function validateCredentials(array $credentials): bool;

    /**
     * Estimate cost in platform credits for the given job.
     * Return 0 when costs are billed directly to the user's provider account.
     */
    public function estimateCost(ComputeJobDTO $job): int;
}
