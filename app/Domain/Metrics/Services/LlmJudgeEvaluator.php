<?php

namespace App\Domain\Metrics\Services;

use App\Domain\Metrics\DTOs\QualityEvaluationDTO;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Facades\Log;

class LlmJudgeEvaluator
{
    public function __construct(
        private AiGatewayInterface $gateway,
        private ProviderResolver $providerResolver,
    ) {}

    /**
     * Evaluate an execution output using LLM-as-judge.
     *
     * @param  array<string>  $criteria
     */
    public function evaluate(
        string $prompt,
        string $output,
        string $teamId,
        ?string $sourceModel = null,
        array $criteria = ['relevance', 'accuracy', 'completeness'],
        ?string $overrideJudgeModel = null,
        ?string $userId = null,
    ): ?QualityEvaluationDTO {
        $judgeConfig = $this->resolveJudgeModel($teamId, $sourceModel, $overrideJudgeModel);

        if (! $judgeConfig) {
            Log::debug('LlmJudgeEvaluator: No suitable judge model found (only one model available, same as source)');

            return null;
        }

        $criteriaList = implode(', ', $criteria);

        $request = new AiRequestDTO(
            provider: $judgeConfig['provider'],
            model: $judgeConfig['model'],
            systemPrompt: 'You are a quality evaluation judge. Evaluate the given AI output against the original prompt. Score each dimension from 0.0 to 1.0 and provide brief feedback. Return ONLY valid JSON (no markdown, no code fences) with: overall_score (float 0.0-1.0), dimensions (object mapping each criterion to a float score), feedback (string, max 3 sentences summarizing quality).',
            userPrompt: "Evaluate this output on the following criteria: {$criteriaList}\n\n--- ORIGINAL PROMPT ---\n{$prompt}\n\n--- AI OUTPUT ---\n{$output}",
            maxTokens: 512,
            userId: $userId ?? Team::ownerIdFor($teamId),
            teamId: $teamId,
            purpose: 'quality_evaluation',
            temperature: 0.1,
        );

        try {
            $response = $this->gateway->complete($request);

            $parsed = json_decode($response->content, true);
            if (! $parsed) {
                // Try stripping markdown code fences
                $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($response->content));
                $parsed = json_decode($cleaned, true);
            }

            if (! is_array($parsed) || ! isset($parsed['overall_score'])) {
                Log::warning('LlmJudgeEvaluator: Invalid response structure', [
                    'response' => substr($response->content, 0, 500),
                ]);

                return null;
            }

            return new QualityEvaluationDTO(
                overallScore: max(0.0, min(1.0, (float) $parsed['overall_score'])),
                dimensionScores: $parsed['dimensions'] ?? [],
                feedback: $parsed['feedback'] ?? '',
                evaluationMethod: 'llm_judge',
                judgeModel: $judgeConfig['provider'].'/'.$judgeConfig['model'],
            );
        } catch (\Throwable $e) {
            Log::warning('LlmJudgeEvaluator: Evaluation failed', [
                'error' => $e->getMessage(),
                'judge' => $judgeConfig['provider'].'/'.$judgeConfig['model'],
            ]);

            return null;
        }
    }

    /**
     * Resolve judge model. Anti-bias: never use the same model that generated the output.
     *
     * @return array{provider: string, model: string}|null
     */
    private function resolveJudgeModel(
        string $teamId,
        ?string $sourceModel,
        ?string $overrideJudgeModel,
    ): ?array {
        // Use explicit override if provided
        if ($overrideJudgeModel) {
            $parts = explode('/', $overrideJudgeModel, 2);
            if (count($parts) === 2) {
                return ['provider' => $parts[0], 'model' => $parts[1]];
            }
        }

        // Resolve team default
        $team = Team::withoutGlobalScopes()->find($teamId);
        $default = $this->providerResolver->resolve(team: $team);
        $defaultKey = $default['provider'].'/'.$default['model'];

        // If team default differs from source model, use it
        if ($defaultKey !== $sourceModel) {
            return $default;
        }

        // Try fallback chains — pick first model that differs from source
        $fallbackChains = config('ai.fallback_chains', []);
        foreach ($fallbackChains as $chain) {
            foreach ($chain as $fallback) {
                $fallbackKey = $fallback['provider'].'/'.$fallback['model'];
                if ($fallbackKey !== $sourceModel) {
                    return $fallback;
                }
            }
        }

        // No alternative found
        return null;
    }
}
