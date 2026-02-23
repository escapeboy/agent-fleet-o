<?php

namespace App\Domain\Metrics\Jobs;

use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Metrics\Services\LlmJudgeEvaluator;
use App\Domain\Skill\Models\SkillExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EvaluateExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(
        private readonly string $executionType,
        private readonly string $executionId,
    ) {
        $this->onQueue('metrics');
    }

    public function handle(LlmJudgeEvaluator $evaluator): void
    {
        $execution = $this->resolveExecution();

        if (! $execution) {
            Log::warning('EvaluateExecutionJob: Execution not found', [
                'type' => $this->executionType,
                'id' => $this->executionId,
            ]);

            return;
        }

        // Skip if already evaluated
        if ($execution->quality_score !== null) {
            return;
        }

        $prompt = is_array($execution->input)
            ? json_encode($execution->input, JSON_UNESCAPED_UNICODE)
            : (string) $execution->input;

        $output = is_array($execution->output)
            ? json_encode($execution->output, JSON_UNESCAPED_UNICODE)
            : (string) $execution->output;

        if (empty($prompt) || empty($output)) {
            return;
        }

        // Determine source model for anti-bias
        $sourceModel = $this->resolveSourceModel($execution);

        // Determine custom criteria
        $criteria = $this->resolveCriteria($execution);

        // Determine override judge model
        $overrideJudge = $this->resolveJudgeOverride($execution);

        $result = $evaluator->evaluate(
            prompt: $prompt,
            output: $output,
            teamId: $execution->team_id,
            sourceModel: $sourceModel,
            criteria: $criteria,
            overrideJudgeModel: $overrideJudge,
        );

        if (! $result) {
            Log::debug('EvaluateExecutionJob: No evaluation result (judge unavailable or failed)', [
                'type' => $this->executionType,
                'id' => $this->executionId,
            ]);

            return;
        }

        $execution->update([
            'quality_score' => $result->overallScore,
            'quality_details' => [
                'dimensions' => $result->dimensionScores,
                'feedback' => $result->feedback,
            ],
            'evaluation_method' => $result->evaluationMethod,
            'judge_model' => $result->judgeModel,
        ]);

        Log::info("EvaluateExecutionJob: Scored {$this->executionType} {$this->executionId}", [
            'score' => $result->overallScore,
            'judge' => $result->judgeModel,
        ]);
    }

    private function resolveExecution(): AgentExecution|SkillExecution|null
    {
        return match ($this->executionType) {
            'agent' => AgentExecution::withoutGlobalScopes()->find($this->executionId),
            'skill' => SkillExecution::withoutGlobalScopes()->find($this->executionId),
            default => null,
        };
    }

    private function resolveSourceModel(AgentExecution|SkillExecution $execution): ?string
    {
        if ($execution instanceof AgentExecution) {
            $agent = $execution->agent;

            return $agent ? $agent->provider.'/'.$agent->model : null;
        }

        $skill = $execution->skill;
        $config = $skill?->configuration ?? [];

        return ! empty($config['provider']) && ! empty($config['model'])
            ? $config['provider'].'/'.$config['model']
            : null;
    }

    private function resolveCriteria(AgentExecution|SkillExecution $execution): array
    {
        $default = ['relevance', 'accuracy', 'completeness'];

        if ($execution instanceof AgentExecution) {
            return $execution->agent?->evaluation_criteria ?? $default;
        }

        return $execution->skill?->evaluation_criteria ?? $default;
    }

    private function resolveJudgeOverride(AgentExecution|SkillExecution $execution): ?string
    {
        if ($execution instanceof AgentExecution) {
            return $execution->agent?->evaluation_model;
        }

        return $execution->skill?->evaluation_model;
    }
}
