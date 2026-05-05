<?php

namespace App\Infrastructure\AI\Middleware;

use App\Domain\Budget\Services\CostCalculator;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Enums\BudgetPressureLevel;
use App\Infrastructure\AI\Enums\ReasoningEffort;
use App\Infrastructure\AI\Enums\RequestComplexity;
use App\Infrastructure\AI\Services\ComplexityClassifier;
use Closure;
use Illuminate\Support\Facades\Log;

class BudgetPressureRouting implements AiMiddlewareInterface
{
    public function __construct(
        private readonly ComplexityClassifier $classifier,
        private readonly CostCalculator $costCalculator,
    ) {}

    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO
    {
        if (! $request->teamId) {
            return $next($request);
        }

        $classified = $this->classifier->classify($request);
        $pressure = $this->costCalculator->getBudgetPressureLevel($request->teamId);

        // When effort=Auto, resolve to a concrete thinkingBudget based on classified complexity.
        // Under budget pressure we suppress extended thinking for Auto requests to save costs.
        $resolvedThinkingBudget = $request->thinkingBudget;
        if ($request->effort === ReasoningEffort::Auto && $resolvedThinkingBudget === null) {
            $resolvedThinkingBudget = $pressure === BudgetPressureLevel::None
                ? ReasoningEffort::fromComplexity($classified)
                : null;
        }

        if ($pressure === BudgetPressureLevel::None) {
            return $next(new AiRequestDTO(
                provider: $request->provider,
                model: $request->model,
                systemPrompt: $request->systemPrompt,
                userPrompt: $request->userPrompt,
                maxTokens: $request->maxTokens,
                outputSchema: $request->outputSchema,
                userId: $request->userId,
                teamId: $request->teamId,
                experimentId: $request->experimentId,
                experimentStageId: $request->experimentStageId,
                agentId: $request->agentId,
                purpose: $request->purpose,
                idempotencyKey: $request->idempotencyKey,
                temperature: $request->temperature,
                fallbackChain: $request->fallbackChain,
                tools: $request->tools,
                maxSteps: $request->maxSteps,
                toolChoice: $request->toolChoice,
                providerName: $request->providerName,
                thinkingBudget: $resolvedThinkingBudget,
                effort: $request->effort,
                workingDirectory: $request->workingDirectory,
                enablePromptCaching: $request->enablePromptCaching,
                complexity: $request->complexity,
                classifiedComplexity: $classified,
                budgetPressureLevel: $pressure,
                escalationAttempts: $request->escalationAttempts,
                fastMode: $request->fastMode,
            ));
        }

        $downgraded = $this->applyDowngrade($classified, $pressure, $request);

        $resolved = $this->resolveModelForTier($downgraded, $request);

        Log::info('BudgetPressureRouting: downgraded request', [
            'team_id' => $request->teamId,
            'pressure' => $pressure->value,
            'original_complexity' => $classified->value,
            'downgraded_complexity' => $downgraded->value,
            'original_model' => "{$request->provider}/{$request->model}",
            'routed_model' => $resolved ? "{$resolved['provider']}/{$resolved['model']}" : 'unchanged',
        ]);

        return $next(new AiRequestDTO(
            provider: $resolved['provider'] ?? $request->provider,
            model: $resolved['model'] ?? $request->model,
            systemPrompt: $request->systemPrompt,
            userPrompt: $request->userPrompt,
            maxTokens: $request->maxTokens,
            outputSchema: $request->outputSchema,
            userId: $request->userId,
            teamId: $request->teamId,
            experimentId: $request->experimentId,
            experimentStageId: $request->experimentStageId,
            agentId: $request->agentId,
            purpose: $request->purpose,
            idempotencyKey: $request->idempotencyKey,
            temperature: $request->temperature,
            fallbackChain: $request->fallbackChain,
            tools: $request->tools,
            maxSteps: $request->maxSteps,
            toolChoice: $request->toolChoice,
            providerName: $request->providerName,
            thinkingBudget: $resolvedThinkingBudget,
            effort: $request->effort,
            workingDirectory: $request->workingDirectory,
            enablePromptCaching: $request->enablePromptCaching,
            complexity: $request->complexity,
            classifiedComplexity: $classified,
            budgetPressureLevel: $pressure,
            escalationAttempts: $request->escalationAttempts,
            fastMode: $request->fastMode,
        ));
    }

    private function applyDowngrade(
        RequestComplexity $classified,
        BudgetPressureLevel $pressure,
        AiRequestDTO $request,
    ): RequestComplexity {
        $toolCount = $request->tools ? count($request->tools) : 0;
        $minToolsForStandard = (int) config('ai_routing.budget_pressure.min_tools_for_standard', 5);

        return match ($pressure) {
            BudgetPressureLevel::Low => match ($classified) {
                RequestComplexity::Heavy => RequestComplexity::Standard,
                default => $classified,
            },

            BudgetPressureLevel::Medium => match ($classified) {
                RequestComplexity::Heavy => RequestComplexity::Standard,
                RequestComplexity::Standard => $toolCount > $minToolsForStandard
                    ? RequestComplexity::Standard
                    : RequestComplexity::Light,
                default => $classified,
            },

            BudgetPressureLevel::High => match (true) {
                $toolCount > $minToolsForStandard => RequestComplexity::Standard,
                $request->complexity === RequestComplexity::Heavy => RequestComplexity::Standard,
                default => RequestComplexity::Light,
            },

            default => $classified,
        };
    }

    /**
     * @return array{provider: string, model: string}|null
     */
    private function resolveModelForTier(RequestComplexity $tier, AiRequestDTO $request): ?array
    {
        $tierName = $tier->toModelTier();
        $tierModels = config("experiments.model_tiers.{$tierName}");

        // 'standard' tier (null) means keep the team's default — no override
        if ($tierModels === null) {
            return null;
        }

        // Try the current provider first
        if (isset($tierModels[$request->provider])) {
            return [
                'provider' => $request->provider,
                'model' => $tierModels[$request->provider],
            ];
        }

        // Fall back to the first provider the team has credentials for
        $teamProviders = TeamProviderCredential::withoutGlobalScopes()
            ->where('team_id', $request->teamId)
            ->where('is_active', true)
            ->pluck('provider')
            ->all();

        foreach ($tierModels as $provider => $model) {
            if (in_array($provider, $teamProviders, true)) {
                return ['provider' => $provider, 'model' => $model];
            }
        }

        // No matching credentials — use first available tier model
        $firstProvider = array_key_first($tierModels);

        return ['provider' => $firstProvider, 'model' => $tierModels[$firstProvider]];
    }
}
