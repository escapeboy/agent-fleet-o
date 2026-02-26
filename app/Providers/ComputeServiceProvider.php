<?php

namespace App\Providers;

use App\Infrastructure\Compute\ComputeProviderManager;
use App\Infrastructure\Compute\Contracts\ComputeProviderInterface;
use App\Infrastructure\Compute\Services\ComputeCostEstimator;
use App\Infrastructure\Compute\Services\ComputeCredentialResolver;
use Illuminate\Support\ServiceProvider;

class ComputeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/compute_providers.php',
            'compute_providers'
        );

        $this->app->singleton(ComputeCostEstimator::class);
        $this->app->singleton(ComputeCredentialResolver::class);

        $this->app->singleton(ComputeProviderManager::class, function ($app) {
            return new ComputeProviderManager($app);
        });

        // Bind the interface to the default driver for DI injection
        $this->app->singleton(ComputeProviderInterface::class, function ($app) {
            return $app->make(ComputeProviderManager::class)->driver();
        });
    }
}
