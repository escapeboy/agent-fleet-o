<?php

namespace App\Infrastructure\AI\Services;

use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Enums\FailureType;
use App\Infrastructure\AI\Enums\RequestComplexity;
use App\Models\GlobalSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Throwable;

class EscalationStrategy
{
    public function __construct(
        private readonly CostCalculator $costCalculator,
    ) {}

    /**
     * Classify a throwable into a FailureType.
     */
    public function classifyFailure(Throwable $e): FailureType
    {
        if ($e instanceof PrismRateLimitedException) {
            return FailureType::RateLimit;
        }

        if ($e instanceof InsufficientBudgetException) {
            return FailureType::BudgetExhausted;
        }

        // Schema validation or JSON decode errors → quality failure
        if (
            str_contains($e::class, 'SchemaValidation')
            || $e instanceof \JsonException
            || str_contains($e->getMessage(), 'json_decode')
            || str_contains($e->getMessage(), 'JSON')
        ) {
            return FailureType::QualityFailure;
        }

        // Empty/null response content → quality failure
        if (
            str_contains($e->getMessage(), 'empty response')
            || str_contains($e->getMessage(), 'Empty content')
            || str_contains($e->getMessage(), 'no content')
        ) {
            return FailureType::QualityFailure;
        }

        // Auth/credential errors
        if ($e instanceof \RuntimeException && (
            str_contains($e->getMessage(), 'Missing')
            || str_contains($e->getMessage(), 'credential')
        )) {
            return FailureType::AuthError;
        }

        // Timeout-related exceptions
        if (
            $e instanceof ConnectionException
            || str_contains($e->getMessage(), 'timed out')
            || str_contains($e->getMessage(), 'timeout')
            || str_contains($e->getMessage(), 'cURL error 28')
        ) {
            return FailureType::Timeout;
        }

        return FailureType::ProviderError;
    }

    /**
     * Determine escalated model for the given provider.
     * Returns null if escalation not possible (already at max, disabled, or budget insufficient).
     *
     * @return array{provider: string, model: string}|null
     */
    public function getEscalatedModel(
        AiRequestDTO $request,
        string $currentProvider,
        string $currentModel,
    ): ?array {
        $enabled = GlobalSetting::get('ai_routing.escalation_enabled') ?? config('ai_routing.escalation.enabled', true);
        if (! $enabled) {
            return null;
        }

        $maxAttempts = (int) (GlobalSetting::get('ai_routing.escalation_max_attempts') ?? config('ai_routing.escalation.max_attempts', 2));

        if ($request->escalationAttempts >= $maxAttempts) {
            Log::debug('EscalationStrategy: max escalation attempts reached', [
                'attempts' => $request->escalationAttempts,
                'max' => $maxAttempts,
            ]);

            return null;
        }

        // Determine current tier by reverse-mapping the model name
        $currentTier = $this->resolveCurrentTier($currentProvider, $currentModel);

        if ($currentTier === null) {
            Log::debug('EscalationStrategy: could not determine current tier', [
                'provider' => $currentProvider,
                'model' => $currentModel,
            ]);

            return null;
        }

        // Get the next tier up
        $escalatedTier = $currentTier->escalate();

        if ($escalatedTier === null) {
            Log::debug('EscalationStrategy: already at highest tier', [
                'current' => $currentTier->value,
            ]);

            return null;
        }

        // Resolve concrete model for the escalated tier + same provider
        $tierModels = config('experiments.model_tiers.'.$escalatedTier->toModelTier());

        if ($tierModels === null || ! is_array($tierModels)) {
            // 'standard' tier is null (use team default) — can't resolve a concrete model
            Log::debug('EscalationStrategy: escalated tier has no concrete models', [
                'tier' => $escalatedTier->toModelTier(),
            ]);

            return null;
        }

        $escalatedModel = $tierModels[$currentProvider] ?? null;

        if ($escalatedModel === null) {
            Log::debug('EscalationStrategy: no model for provider at escalated tier', [
                'provider' => $currentProvider,
                'tier' => $escalatedTier->toModelTier(),
            ]);

            return null;
        }

        // Same model — no point in escalating
        if ($escalatedModel === $currentModel) {
            return null;
        }

        Log::info('EscalationStrategy: escalating model', [
            'provider' => $currentProvider,
            'from' => $currentModel,
            'to' => $escalatedModel,
            'tier' => $currentTier->value.' → '.$escalatedTier->value,
            'attempt' => $request->escalationAttempts + 1,
        ]);

        return ['provider' => $currentProvider, 'model' => $escalatedModel];
    }

    /**
     * Reverse-map a provider/model pair to its RequestComplexity tier.
     */
    private function resolveCurrentTier(string $provider, string $model): ?RequestComplexity
    {
        $tiers = config('experiments.model_tiers', []);

        foreach ($tiers as $tierName => $models) {
            if (! is_array($models)) {
                continue;
            }

            if (isset($models[$provider]) && $models[$provider] === $model) {
                return match ($tierName) {
                    'cheap' => RequestComplexity::Light,
                    'standard' => RequestComplexity::Standard,
                    'expensive' => RequestComplexity::Heavy,
                    default => null,
                };
            }
        }

        // Model not found in any tier — assume standard (team default)
        return RequestComplexity::Standard;
    }
}
