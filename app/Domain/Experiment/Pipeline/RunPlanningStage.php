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
        $llm = $this->resolvePipelineLlm($experiment);

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
            "Scoring output: " . json_encode($scoringOutput, JSON_UNESCAPED_UNICODE),
        ];

        if ($rejectionFeedback) {
            $contextParts[] = "Previous rejection feedback: " . json_encode($rejectionFeedback, JSON_UNESCAPED_UNICODE);
        }

        // Include dependency context from predecessor projects
        $dependencyContext = $experiment->constraints['dependency_context'] ?? [];
        if (! empty($dependencyContext)) {
            $contextParts[] = "\n--- Context from predecessor projects ---";
            foreach ($dependencyContext as $alias => $data) {
                $contextParts[] = "[{$alias}] Project: {$data['project_title']} (Run #{$data['run_number']})";
                foreach ($data['artifacts'] ?? [] as $artifact) {
                    $contextParts[] = "Artifact [{$artifact['type']}] {$artifact['name']}:\n{$artifact['content']}";
                }
                if (! empty($data['stage_outputs'])) {
                    $contextParts[] = "Stage outputs: " . json_encode($data['stage_outputs'], JSON_UNESCAPED_UNICODE);
                }
            }
        }

        $request = new AiRequestDTO(
            provider: $llm['provider'],
            model: $llm['model'],
            systemPrompt: 'You are an experiment planning agent. Create an execution plan. If context from predecessor projects is provided, incorporate their findings and artifacts into your plan. Return ONLY a valid JSON object (no markdown, no code fences) with: plan_summary (string, max 3 sentences), artifacts_to_build (array of {type, name, description}), outbound_channels (array of {channel, target_description}), success_metrics (array of max 5 strings), estimated_timeline_hours (int). Keep compact.',
            userPrompt: implode("\n\n", $contextParts),
            maxTokens: 2048,
            userId: $experiment->user_id,
            experimentId: $experiment->id,
            experimentStageId: $stage->id,
            purpose: 'planning',
            temperature: 0.5,
            teamId: $experiment->team_id,
        );

        $response = $gateway->complete($request);

        $parsedOutput = $this->parseJsonResponse($response);

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
