<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Models\Artifact;
use App\Models\ArtifactVersion;

class RunBuildingStage extends BaseStageJob
{
    public function __construct(string $experimentId)
    {
        parent::__construct($experimentId);
        $this->onQueue('ai-calls');
    }

    protected function expectedState(): ExperimentStatus
    {
        return ExperimentStatus::Building;
    }

    protected function stageType(): StageType
    {
        return StageType::Building;
    }

    protected function process(Experiment $experiment, ExperimentStage $stage): void
    {
        $gateway = app(AiGatewayInterface::class);
        $transition = app(TransitionExperimentAction::class);

        // Get the plan from planning stage
        $planningStage = $experiment->stages()
            ->where('stage', StageType::Planning)
            ->where('iteration', $experiment->current_iteration)
            ->latest()
            ->first();

        $plan = $planningStage?->output_snapshot ?? [];
        $artifactsToBuild = $plan['artifacts_to_build'] ?? [
            ['type' => 'email_template', 'name' => 'outreach_email', 'description' => 'Outreach email for experiment'],
        ];

        $builtArtifacts = [];

        foreach ($artifactsToBuild as $artifactSpec) {
            $request = new AiRequestDTO(
                provider: 'anthropic',
                model: 'claude-sonnet-4-5-20250929',
                systemPrompt: "You are a content builder agent. Generate the requested artifact content. Return a JSON object with: content (string - the actual artifact content), metadata (object - any relevant metadata about the artifact).",
                userPrompt: "Build this artifact:\n\nType: {$artifactSpec['type']}\nName: {$artifactSpec['name']}\nDescription: {$artifactSpec['description']}\n\nExperiment context:\nTitle: {$experiment->title}\nThesis: {$experiment->thesis}\nPlan: " . json_encode($plan),
                maxTokens: 2048,
                userId: $experiment->user_id,
                experimentId: $experiment->id,
                experimentStageId: $stage->id,
                purpose: 'building',
                temperature: 0.7,
            );

            $response = $gateway->complete($request);
            $output = $response->parsedOutput ?? json_decode($response->content, true);

            // Create artifact and version
            $artifact = Artifact::create([
                'experiment_id' => $experiment->id,
                'type' => $artifactSpec['type'],
                'name' => $artifactSpec['name'],
                'current_version' => 1,
                'metadata' => $output['metadata'] ?? [],
            ]);

            ArtifactVersion::create([
                'artifact_id' => $artifact->id,
                'version' => 1,
                'content' => $output['content'] ?? $response->content,
                'metadata' => ['iteration' => $experiment->current_iteration],
            ]);

            $builtArtifacts[] = [
                'artifact_id' => $artifact->id,
                'type' => $artifactSpec['type'],
                'name' => $artifactSpec['name'],
            ];
        }

        $stage->update([
            'output_snapshot' => [
                'artifacts_built' => $builtArtifacts,
                'plan' => $plan,
            ],
        ]);

        $transition->execute(
            experiment: $experiment,
            toState: ExperimentStatus::AwaitingApproval,
            reason: 'Artifacts built, awaiting approval',
        );
    }
}
