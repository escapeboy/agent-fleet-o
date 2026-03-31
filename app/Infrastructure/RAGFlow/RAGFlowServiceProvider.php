<?php

namespace App\Infrastructure\RAGFlow;

use Illuminate\Support\ServiceProvider;

class RAGFlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../../config/ragflow.php',
            'ragflow',
        );

        $this->app->singleton(RAGFlowClient::class, function (): RAGFlowClient {
            return new RAGFlowClient(
                baseUrl: config('ragflow.url'),
                apiKey: config('ragflow.api_key') ?? '',
                timeout: config('ragflow.timeout', 30),
                circuitBreakerTtl: config('ragflow.circuit_breaker_ttl', 60),
                circuitBreakerThreshold: config('ragflow.circuit_breaker_threshold', 5),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../../config/ragflow.php' => config_path('ragflow.php'),
            ], 'ragflow-config');
        }
    }
}
