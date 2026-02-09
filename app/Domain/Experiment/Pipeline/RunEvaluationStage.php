<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;

class RunEvaluationStage extends BaseStageJob
{
    public function __construct(string $experimentId)
    {
        parent::__construct($experimentId);
        $this->onQueue('ai-calls');
    }

    protected function expectedState(): ExperimentStatus
    {
        return ExperimentStatus::Evaluating;
    }

    protected function stageType(): StageType
    {
        return StageType::Evaluating;
    }

    protected function process(Experiment $experiment, ExperimentStage $stage): void
    {
        $gateway = app(AiGatewayInterface::class);
        $transition = app(TransitionExperimentAction::class);
        $llm = $this->resolvePipelineLlm($experiment);

        // Gather all metrics for this experiment
        $metrics = $experiment->metrics()
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('type')
            ->map(fn ($group) => [
                'count' => $group->count(),
                'avg' => round($group->avg('value'), 4),
                'sum' => round($group->sum('value'), 4),
            ]);

        $request = new AiRequestDTO(
            provider: $llm['provider'],
            model: $llm['model'],
            systemPrompt: 'You are an experiment evaluation agent. Analyze metrics and decide the outcome. Return ONLY a valid JSON object (no markdown, no code fences) with: verdict (completed|iterate|kill), reasoning (string, max 2 sentences), confidence (0.0-1.0), key_findings (array of max 5 strings), recommendations (array of max 3 strings). Keep compact.',
            userPrompt: "Evaluate this experiment:\n\nTitle: {$experiment->title}\nThesis: {$experiment->thesis}\nIteration: {$experiment->current_iteration} of {$experiment->max_iterations}\nSuccess criteria: " . json_encode($experiment->success_criteria) . "\nMetrics: " . json_encode($metrics),
            maxTokens: 1024,
            userId: $experiment->user_id,
            experimentId: $experiment->id,
            experimentStageId: $stage->id,
            purpose: 'evaluating',
            temperature: 0.3,
            teamId: $experiment->team_id,
        );

        $response = $gateway->complete($request);
        $parsedOutput = $this->parseJsonResponse($response);

        $stage->update([
            'output_snapshot' => $parsedOutput,
        ]);

        $verdict = $parsedOutput['verdict'] ?? 'completed';

        match ($verdict) {
            'iterate' => $this->handleIterate($experiment, $transition),
            'kill' => $transition->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Killed,
                reason: $parsedOutput['reasoning'] ?? 'Below threshold after evaluation',
            ),
            default => $transition->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Completed,
                reason: $parsedOutput['reasoning'] ?? 'Success criteria met',
            ),
        };
    }

    private function handleIterate(Experiment $experiment, TransitionExperimentAction $transition): void
    {
        if ($experiment->current_iteration >= $experiment->max_iterations) {
            $transition->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Killed,
                reason: "Max iterations ({$experiment->max_iterations}) reached",
            );
            return;
        }

        $experiment->increment('current_iteration');

        $transition->execute(
            experiment: $experiment,
            toState: ExperimentStatus::Iterating,
            reason: "Iterating to cycle {$experiment->current_iteration}",
        );
    }
}
