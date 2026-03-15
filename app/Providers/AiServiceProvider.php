<?php

namespace App\Providers;

use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\Gateways\FallbackAiGateway;
use App\Infrastructure\AI\Gateways\LocalAgentGateway;
use App\Infrastructure\AI\Gateways\LocalBridgeGateway;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use App\Infrastructure\AI\Middleware\BudgetEnforcement;
use App\Infrastructure\AI\Middleware\IdempotencyCheck;
use App\Infrastructure\AI\Middleware\RateLimiting;
use App\Infrastructure\AI\Middleware\SchemaValidation;
use App\Infrastructure\AI\Middleware\SemanticCache;
use App\Infrastructure\AI\Middleware\UsageTracking;
use App\Infrastructure\AI\Services\CircuitBreaker;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use App\Infrastructure\AI\Services\LocalLlmDiscovery;
use App\Infrastructure\AI\Services\LocalLlmUrlValidator;
use App\Infrastructure\Bridge\BridgeRequestRegistry;
use Illuminate\Support\ServiceProvider;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\OpenRouter\OpenRouter;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CostCalculator::class);
        $this->app->singleton(CircuitBreaker::class);
        $this->app->singleton(LocalAgentDiscovery::class);
        $this->app->singleton(LocalLlmDiscovery::class);
        $this->app->singleton(LocalLlmUrlValidator::class);

        $this->app->singleton(LocalAgentGateway::class, function ($app) {
            return new LocalAgentGateway(
                discovery: $app->make(LocalAgentDiscovery::class),
            );
        });

        $this->app->singleton(PrismAiGateway::class, function ($app) {
            $gateway = new PrismAiGateway(
                costCalculator: $app->make(CostCalculator::class),
            );

            return $gateway->withMiddleware([
                $app->make(RateLimiting::class),
                $app->make(BudgetEnforcement::class),
                $app->make(IdempotencyCheck::class),
                $app->make(SemanticCache::class),
                $app->make(SchemaValidation::class),
                $app->make(UsageTracking::class),
            ]);
        });

        $this->app->singleton(BridgeRequestRegistry::class);

        $this->app->singleton(LocalBridgeGateway::class, function ($app) {
            return new LocalBridgeGateway(
                registry: $app->make(BridgeRequestRegistry::class),
            );
        });

        $this->app->singleton(AiGatewayInterface::class, function ($app) {
            return new FallbackAiGateway(
                gateway: $app->make(PrismAiGateway::class),
                circuitBreaker: $app->make(CircuitBreaker::class),
                fallbackChains: [
                    'anthropic/claude-sonnet-4-5-20250929' => [
                        ['provider' => 'openai', 'model' => 'gpt-4o'],
                    ],
                    'openai/gpt-4o' => [
                        ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5-20250929'],
                    ],
                    'google/gemini-2.5-pro' => [
                        ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5-20250929'],
                    ],
                    'google/gemini-2.5-flash' => [
                        ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5-20250929'],
                    ],
                ],
                localGateway: config('local_agents.enabled')
                    ? $app->make(LocalAgentGateway::class)
                    : null,
                bridgeGateway: $app->make(LocalBridgeGateway::class),
            );
        });
    }

    public function boot(): void
    {
        $appName = config('app.name', 'FleetQ');

        // Custom AI endpoints — always available (not gated by local_llm.enabled).
        // Backed by the OpenRouter driver which uses POST /v1/chat/completions.
        app(PrismManager::class)->extend('custom_endpoint', function ($app, array $config) use ($appName) {
            return new OpenRouter(
                apiKey: $config['api_key'] ?? '',
                url: rtrim($config['url'] ?? '', '/').'/',
                httpReferer: null,
                xTitle: $appName,
            );
        });

        // Perplexity — OpenAI-compatible endpoint (not natively in Prism's Provider enum yet).
        app(PrismManager::class)->extend('perplexity', function ($app, array $config) use ($appName) {
            return new OpenRouter(
                apiKey: $config['api_key'] ?? '',
                url: rtrim($config['url'] ?? 'https://api.perplexity.ai', '/').'/',
                httpReferer: null,
                xTitle: $appName,
            );
        });

        // Fireworks AI — OpenAI-compatible inference endpoint.
        app(PrismManager::class)->extend('fireworks', function ($app, array $config) use ($appName) {
            return new OpenRouter(
                apiKey: $config['api_key'] ?? '',
                url: rtrim($config['url'] ?? 'https://api.fireworks.ai/inference/v1', '/').'/',
                httpReferer: null,
                xTitle: $appName,
            );
        });

        // LiteLLM Proxy — self-hosted proxy that exposes an OpenAI-compatible endpoint
        // for 100+ providers (Bedrock, Vertex AI, Cohere, Together.ai, etc.).
        // Teams register their proxy base URL as a TeamProviderCredential with provider='litellm_proxy'.
        // See https://docs.litellm.ai/docs/proxy/quick_start
        app(PrismManager::class)->extend('litellm_proxy', function ($app, array $config) use ($appName) {
            return new OpenRouter(
                apiKey: $config['api_key'] ?? 'anything',
                url: rtrim($config['url'] ?? 'http://localhost:4000', '/').'/',
                httpReferer: null,
                xTitle: $appName,
            );
        });

        if (! config('local_llm.enabled', false)) {
            return;
        }

        // Register an OpenAI-compatible custom provider backed by the OpenRouter driver.
        // This lets any LM Studio, vLLM, llama.cpp server, or other OpenAI-compatible
        // endpoint work via the standard ->using('openai_compatible', $model, [...]) call.
        app(PrismManager::class)->extend('openai_compatible', function ($app, array $config) use ($appName) {
            return new OpenRouter(
                apiKey: $config['api_key'] ?? '',
                url: rtrim($config['url'] ?? 'http://localhost:1234/v1', '/').'/',
                httpReferer: null,
                xTitle: $appName,
            );
        });
    }
}
