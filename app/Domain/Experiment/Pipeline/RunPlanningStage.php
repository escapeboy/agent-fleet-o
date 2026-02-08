<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;

class RunPlanningStage extends BaseStageJob
{
    public function __construct(string $experimentId)
    {
        parent::__construct($experimentId);
        $this->onQueue('ai-calls');
    }

    protected function expectedState(): ExperimentStatus
    {
        return ExperimentStatus::Planning;
    }

    protected function stageType(): StageType
    {
        return StageType::Planning;
    }

    protected function process(Experiment $experiment, ExperimentStage $stage): void
    {
        $gateway = app(AiGatewayInterface::class);
        $transition = app(TransitionExperimentAction::class);

        // Gather context from prior stages
        $scoringStage = $experiment->stages()
            ->where('stage', StageType::Scoring)
            ->where('iteration', $experiment->current_iteration)
            ->latest()
            ->first();

        $scoringOutput = $scoringStage?->output_snapshot ?? [];

        // Check for rejection feedback from previous cycle
        $rejectionFeedback = $experiment->stateTransitions()
            ->where('to_state', ExperimentStatus::Rejected->value)
            ->latest('created_at')
            ->value('metadata');

        $contextParts = [
            "Title: {$experiment->title}",
            "Thesis: {$experiment->thesis}",
            "Track: {$experiment->track->value}",
            "Iteration: {$experiment->current_iteration}",
            "Scoring output: " . json_encode($scoringOutput),
        ];

        if ($rejectionFeedback) {
            $contextParts[] = "Previous rejection feedback: " . json_encode($rejectionFeedback);
        }

        $request = new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5-20250929',
            systemPrompt: 'You are an experiment planning agent. Create an execution plan for the experiment. Return a JSON object with: plan_summary (string), artifacts_to_build (array of {type, name, description}), outbound_channels (array of {channel, target_description}), success_metrics (array of strings), estimated_timeline_hours (int).',
            userPrompt: implode("\n\n", $contextParts),
            maxTokens: 2048,
            userId: $experiment->user_id,
            experimentId: $experiment->id,
            experimentStageId: $stage->id,
            purpose: 'planning',
            temperature: 0.5,
        );

        $response = $gateway->complete($request);

        $parsedOutput = $response->parsedOutput ?? json_decode($response->content, true);

        $stage->update([
            'output_snapshot' => $parsedOutput,
        ]);

        $transition->execute(
            experiment: $experiment,
            toState: ExperimentStatus::Building,
            reason: 'Plan generated',
        );
    }
}
