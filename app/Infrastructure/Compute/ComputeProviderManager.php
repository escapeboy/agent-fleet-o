<?php

namespace App\Infrastructure\Compute;

use App\Infrastructure\Compute\Contracts\ComputeProviderInterface;
use App\Infrastructure\Compute\Providers\FalComputeProvider;
use App\Infrastructure\Compute\Providers\NullComputeProvider;
use App\Infrastructure\Compute\Providers\ReplicateComputeProvider;
use App\Infrastructure\Compute\Providers\RunPodComputeProvider;
use App\Infrastructure\Compute\Providers\VastAiComputeProvider;
use App\Infrastructure\Compute\Services\ComputeCostEstimator;
use App\Infrastructure\RunPod\RunPodClient;
use Illuminate\Support\Manager;

/**
 * Laravel Manager that resolves compute provider drivers by slug.
 *
 * Usage:
 *   $provider = app(ComputeProviderManager::class)->driver('runpod');
 *   $provider = app(ComputeProviderManager::class)->driver('replicate');
 *
 * New providers are added by:
 *   1. Implementing ComputeProviderInterface
 *   2. Adding a createXxxDriver() method here
 *   3. Registering the provider slug in config/compute_providers.php
 */
class ComputeProviderManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return config('compute_providers.default', 'runpod');
    }

    public function createRunpodDriver(): ComputeProviderInterface
    {
        return new RunPodComputeProvider(
            client: $this->container->make(RunPodClient::class),
            costEstimator: $this->container->make(ComputeCostEstimator::class),
        );
    }

    public function createReplicateDriver(): ComputeProviderInterface
    {
        return new ReplicateComputeProvider;
    }

    public function createFalDriver(): ComputeProviderInterface
    {
        return new FalComputeProvider;
    }

    public function createVastDriver(): ComputeProviderInterface
    {
        return new VastAiComputeProvider;
    }

    public function createNullDriver(): ComputeProviderInterface
    {
        return new NullComputeProvider;
    }
}
