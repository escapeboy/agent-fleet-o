<?php

namespace App\Providers;

use App\Domain\Budget\Actions\ReserveBudgetAction;
use App\Domain\Budget\Actions\SettleBudgetAction;
use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\Gateways\FallbackAiGateway;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use App\Infrastructure\AI\Middleware\BudgetEnforcement;
use App\Infrastructure\AI\Middleware\IdempotencyCheck;
use App\Infrastructure\AI\Middleware\RateLimiting;
use App\Infrastructure\AI\Middleware\SchemaValidation;
use App\Infrastructure\AI\Middleware\UsageTracking;
use App\Infrastructure\AI\Services\CircuitBreaker;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CostCalculator::class);
        $this->app->singleton(CircuitBreaker::class);

        $this->app->singleton(PrismAiGateway::class, function ($app) {
            $gateway = new PrismAiGateway(
                costCalculator: $app->make(CostCalculator::class),
            );

            return $gateway->withMiddleware([
                $app->make(RateLimiting::class),
                $app->make(BudgetEnforcement::class),
                $app->make(IdempotencyCheck::class),
                $app->make(SchemaValidation::class),
                $app->make(UsageTracking::class),
            ]);
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
                ],
            );
        });
    }
}
