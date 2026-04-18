<?php

namespace App\Domain\Evaluation\Actions;

use App\Domain\Evaluation\Models\EvaluationRunResult;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\Log;

class ScoreEvaluationResultAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    /**
     * Score an evaluation result using an LLM judge.
     *
     * Rates actual vs expected output on a 0.0–1.0 scale and stores
     * the score and reasoning on the result model.
     */
    public function execute(
        EvaluationRunResult $result,
        string $expected,
        string $actual,
        string $judgeModel,
        ?string $judgePrompt = null,
        ?string $teamId = null,
    ): void {
        $prompt = $judgePrompt
            ?? 'Rate the quality of this AI response vs expected. Return JSON: {"score": 0.0-1.0, "reasoning": "string"}.'
               .' Score 1.0 means identical/perfect, 0.0 means completely wrong.'
               ." Expected:\n{expected}\n\nActual:\n{actual}";

        $prompt = str_replace(['{expected}', '{actual}'], [$expected, $actual], $prompt);

        [$provider, $modelName] = $this->resolveModel($judgeModel);

        try {
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $provider,
                model: $modelName,
                systemPrompt: 'You are an impartial evaluation judge. Always return valid JSON with score and reasoning.',
                userPrompt: $prompt,
                maxTokens: 512,
                teamId: $teamId,
                temperature: 0.1,
            ));

            $parsed = json_decode($response->content, true);

            $score = isset($parsed['score']) ? min(1.0, max(0.0, (float) $parsed['score'])) : null;
            $reasoning = $parsed['reasoning'] ?? $response->content;

            $result->update([
                'score' => $score,
                'judge_reasoning' => $reasoning,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ScoreEvaluationResultAction failed', [
                'result_id' => $result->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Parse "provider/model" string or fall back to anthropic with the given model name.
     *
     * @return array{string, string}
     */
    private function resolveModel(string $judgeModel): array
    {
        if (str_contains($judgeModel, '/')) {
            [$provider, $model] = explode('/', $judgeModel, 2);

            return [$provider, $model];
        }

        // Default to anthropic for claude-* models, openai for gpt-* models
        if (str_starts_with($judgeModel, 'gpt')) {
            return ['openai', $judgeModel];
        }

        return ['anthropic', $judgeModel];
    }
}
