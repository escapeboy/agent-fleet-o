<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Budget\Actions\ReserveBudgetAction;
use App\Domain\Budget\Actions\SettleBudgetAction;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Services\SkillCostCalculator;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Executes a skill prompt against one or more models sequentially and writes
 * results to Redis (TTL 300 s) so that the SkillPlayground Livewire component
 * can poll for updates without keeping an HTTP connection open.
 *
 * Results are NOT written to skill_executions — playground runs are test runs.
 */
class SkillPlaygroundRunAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly SkillCostCalculator $costCalculator,
        private readonly ReserveBudgetAction $reserveBudget,
        private readonly SettleBudgetAction $settleBudget,
    ) {}

    /**
     * Run the skill's prompt template against each selected model.
     *
     * @param  string[]  $models  Provider/model strings, e.g. ["anthropic/claude-sonnet-4-5", "openai/gpt-4o"]
     * @return string The run ID used as part of the Redis key for polling
     */
    public function execute(
        Skill $skill,
        string $input,
        array $models,
        string $teamId,
        string $userId,
    ): string {
        $runId = Str::uuid()->toString();

        // Build the rendered user prompt by substituting {{variable}} placeholders
        $promptTemplate = $skill->configuration['prompt_template'] ?? '';
        $userPrompt = $this->renderTemplate($promptTemplate, ['input' => $input]);
        $systemPrompt = $skill->system_prompt ?? '';

        foreach ($models as $fullModel) {
            [$provider, $model] = $this->splitProviderModel($fullModel);

            // Estimate cost (1.5x safety multiplier applied inside ReserveBudgetAction)
            $estimatedCost = $this->costCalculator->estimate($skill, $provider, $model);

            $reservation = null;
            $startTime = hrtime(true);

            try {
                // Reserve budget before each model call
                $reservation = $this->reserveBudget->execute(
                    userId: $userId,
                    teamId: $teamId,
                    amount: $estimatedCost,
                    description: "Skill playground run: {$skill->name} [{$fullModel}]",
                );

                $response = $this->gateway->complete(new AiRequestDTO(
                    provider: $provider,
                    model: $model,
                    systemPrompt: $systemPrompt,
                    userPrompt: $userPrompt,
                    maxTokens: $skill->configuration['max_tokens'] ?? 2048,
                    userId: $userId,
                    teamId: $teamId,
                    purpose: 'playground',
                    temperature: (float) ($skill->configuration['temperature'] ?? 0.7),
                ));

                $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

                // Settle budget against actual usage
                $actualCost = $this->costCalculator->calculate(
                    $provider,
                    $model,
                    $response->usage->inputTokens,
                    $response->usage->outputTokens,
                );
                $this->settleBudget->execute($reservation, $actualCost);

                $result = [
                    'output' => $response->content,
                    'cost' => $actualCost,
                    'latency_ms' => $latencyMs,
                    'done' => true,
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                // Settle reservation at zero cost on failure so credits are returned
                if ($reservation) {
                    try {
                        $this->settleBudget->execute($reservation, 0);
                    } catch (\Throwable) {
                        // Ignore settlement errors — do not mask the original error
                    }
                }

                $result = [
                    'output' => null,
                    'cost' => 0,
                    'latency_ms' => null,
                    'done' => true,
                    'error' => $e->getMessage(),
                ];
            }

            // Persist result keyed by (teamId, runId, modelId) with 300 s TTL
            Cache::put(
                "skill_playground:{$teamId}:{$runId}:{$fullModel}",
                $result,
                300,
            );
        }

        return $runId;
    }

    /**
     * Simple {{variable}} substitution; unmatched tokens are left as-is.
     *
     * @param  array<string,string>  $vars
     */
    private function renderTemplate(string $template, array $vars): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($vars) {
            return $vars[$m[1]] ?? $m[0];
        }, $template);
    }

    /**
     * Split "provider/model" into [$provider, $model].
     * Falls back to using the full string as both when "/" is absent.
     */
    private function splitProviderModel(string $fullModel): array
    {
        if (str_contains($fullModel, '/')) {
            [$provider, $model] = explode('/', $fullModel, 2);

            return [$provider, $model];
        }

        return [$fullModel, $fullModel];
    }
}
