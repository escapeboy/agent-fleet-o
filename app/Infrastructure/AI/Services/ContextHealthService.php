<?php

namespace App\Infrastructure\AI\Services;

use App\Domain\Experiment\Models\Experiment;
use App\Infrastructure\AI\DTOs\ContextHealthDTO;
use App\Infrastructure\AI\Models\LlmRequestLog;

/**
 * Monitors LLM context consumption for an experiment.
 *
 * Sums input tokens from llm_request_logs across all stages and
 * computes what fraction of the model's context window has been used.
 * This allows pipeline stages to detect context overflow risk early.
 *
 * Inspired by BroodMind's queen_context_health / queen_context_reset pattern.
 */
class ContextHealthService
{
    /** Warn when context consumption reaches this fraction (80%). */
    private const WARNING_THRESHOLD = 0.80;

    /** Critical level when context consumption reaches this fraction (90%). */
    private const CRITICAL_THRESHOLD = 0.90;

    /** Default context window if the model is not in llm_pricing.context_windows. */
    private const DEFAULT_CONTEXT_WINDOW = 200_000;

    public function getExperimentContextHealth(Experiment $experiment): ContextHealthDTO
    {
        $totalInputTokens = LlmRequestLog::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->whereNotNull('input_tokens')
            ->sum('input_tokens');

        $primaryModel = $this->resolvePrimaryModel($experiment);
        $contextWindow = $this->resolveContextWindow($primaryModel);

        $fraction = $contextWindow > 0
            ? min((int) $totalInputTokens / $contextWindow, 1.0)
            : 0.0;

        return new ContextHealthDTO(
            experimentId: $experiment->id,
            totalInputTokens: (int) $totalInputTokens,
            contextWindowTokens: $contextWindow,
            contextUsedFraction: $fraction,
            isApproachingLimit: $fraction >= self::WARNING_THRESHOLD,
            isCritical: $fraction >= self::CRITICAL_THRESHOLD,
            primaryModel: $primaryModel,
        );
    }

    public function isApproachingLimit(Experiment $experiment, float $threshold = self::WARNING_THRESHOLD): bool
    {
        $health = $this->getExperimentContextHealth($experiment);

        return $health->contextUsedFraction >= $threshold;
    }

    /**
     * Build a structured handoff document summarising the experiment's current state.
     * Stored as an experiment artifact when context approaches the critical threshold.
     *
     * @return array{goal_now: string, done: string[], open_threads: string[], critical_constraints: string[], context_token_summary: array}
     */
    public function buildHandoffDocument(Experiment $experiment): array
    {
        $health = $this->getExperimentContextHealth($experiment);

        return [
            'goal_now' => $experiment->title,
            'done' => array_values(
                $experiment->stages()
                    ->withoutGlobalScopes()
                    ->where('status', 'completed')
                    ->pluck('stage')
                    ->map(fn ($s) => is_string($s) ? $s : $s->value)
                    ->toArray(),
            ),
            'open_threads' => array_values(
                $experiment->stages()
                    ->withoutGlobalScopes()
                    ->whereNotIn('status', ['completed', 'failed', 'skipped'])
                    ->pluck('stage')
                    ->map(fn ($s) => is_string($s) ? $s : $s->value)
                    ->toArray(),
            ),
            'critical_constraints' => array_values(
                collect($experiment->constraints ?? [])->map(
                    fn ($v, $k) => "{$k}: ".json_encode($v),
                )->toArray(),
            ),
            'context_token_summary' => [
                'total_input_tokens' => $health->totalInputTokens,
                'context_window' => $health->contextWindowTokens,
                'context_used_pct' => $health->contextUsedPercent(),
                'primary_model' => $health->primaryModel,
            ],
        ];
    }

    private function resolvePrimaryModel(Experiment $experiment): string
    {
        // Use the most recently used model for this experiment, falling back to config default
        $lastModel = LlmRequestLog::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->orderByDesc('created_at')
            ->value('model');

        return $lastModel
            ?? $experiment->config['llm']['model']
            ?? config('llm_pricing.default_model', 'claude-sonnet-4-5-20250929');
    }

    private function resolveContextWindow(string $model): int
    {
        $windows = config('llm_pricing.context_windows', []);

        // Try exact match, then prefix match (e.g. 'claude-sonnet-4-5' matches 'claude-sonnet-4-5-20250929')
        if (isset($windows[$model])) {
            return (int) $windows[$model];
        }

        foreach ($windows as $configModel => $window) {
            if (str_starts_with($model, $configModel) || str_starts_with($configModel, $model)) {
                return (int) $window;
            }
        }

        return self::DEFAULT_CONTEXT_WINDOW;
    }
}
