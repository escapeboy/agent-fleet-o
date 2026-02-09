<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;

class RunScoringStage extends BaseStageJob
{
    public function __construct(string $experimentId)
    {
        parent::__construct($experimentId);
        $this->onQueue('ai-calls');
    }

    protected function expectedState(): ExperimentStatus
    {
        return ExperimentStatus::Scoring;
    }

    protected function stageType(): StageType
    {
        return StageType::Scoring;
    }

    protected function process(Experiment $experiment, ExperimentStage $stage): void
    {
        $gateway = app(AiGatewayInterface::class);
        $transition = app(TransitionExperimentAction::class);
        $llm = $this->resolvePipelineLlm($experiment);

        $signal = $experiment->signals()->latest()->first();
        $signalPayload = $signal?->payload ?? ['thesis' => $experiment->thesis];

        $request = new AiRequestDTO(
            provider: $llm['provider'],
            model: $llm['model'],
            systemPrompt: 'You are an experiment scoring agent. Evaluate the business potential of the given signal or thesis. Return ONLY a valid JSON object (no markdown, no code fences) with: score (0.0-1.0), reasoning (string, max 2 sentences), recommended_track (growth|retention|revenue|engagement), and key_metrics (array of max 5 short strings). Keep the response compact.',
            userPrompt: "Score this experiment thesis:\n\nTitle: {$experiment->title}\nThesis: {$experiment->thesis}\nSignal: " . json_encode($signalPayload),
            maxTokens: 1024,
            userId: $experiment->user_id,
            experimentId: $experiment->id,
            experimentStageId: $stage->id,
            purpose: 'scoring',
            temperature: 0.3,
            teamId: $experiment->team_id,
        );

        $response = $gateway->complete($request);

        $parsedOutput = $this->parseJsonResponse($response);
        $score = $parsedOutput['score'] ?? 0.0;

        // Update signal with score
        if ($signal) {
            $signal->update([
                'score' => $score,
                'scoring_details' => $parsedOutput,
                'scored_at' => now(),
            ]);
        }

        $stage->update([
            'output_snapshot' => $parsedOutput,
        ]);

        // Decide next transition
        $threshold = $experiment->constraints['score_threshold'] ?? 0.3;

        if ($score >= $threshold) {
            $transition->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Planning,
                reason: "Score {$score} above threshold {$threshold}",
            );
        } else {
            $transition->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Discarded,
                reason: "Score {$score} below threshold {$threshold}",
            );
        }
    }
}
