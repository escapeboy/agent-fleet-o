<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Memory\Services\MemoryContextInjector;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Signal\Models\Signal;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;

class RunScoringStage extends BaseStageJob
{
    /**
     * Ultimate fallback scoring question, used when config('experiments.scoring_rubrics')
     * is missing/empty — so a config-cache miss can never break scoring; it
     * degrades to the historical business-potential behaviour.
     */
    private const FALLBACK_PROMPT = 'You are an experiment scoring agent. Evaluate the business potential of the given signal or thesis. If context from predecessor projects is provided, factor it into your evaluation. Return ONLY a valid JSON object (no markdown, no code fences) with: score (0.0-1.0), reasoning (string, max 2 sentences), recommended_track (growth|retention|revenue|engagement), and key_metrics (array of max 5 short strings). Keep the response compact.';

    public function __construct(string $experimentId, ?string $teamId = null)
    {
        parent::__construct($experimentId, $teamId);
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

        /** @var Signal|null $signal */
        $signal = $experiment->signals()->latest()->first();
        $signalPayload = $signal->payload ?? ['thesis' => $experiment->thesis];
        $rubric = $this->resolveScoringRubric($experiment, $signal);

        // Inject relevant memories from past experiments
        $memoryContext = '';
        $projectId = ProjectRun::where('experiment_id', $experiment->id)->value('project_id');
        if ($projectId) {
            $injector = app(MemoryContextInjector::class);
            $memory = $injector->buildContext(
                agentId: $experiment->agent_id,
                input: $experiment->thesis ?? $experiment->title,
                projectId: $projectId,
                teamId: $experiment->team_id,
            );
            if ($memory) {
                $memoryContext = "\n\n--- Past Learnings ---\n{$memory}";
            }
        }

        // Build user prompt with optional dependency context
        $safeTitle = preg_replace('/[\x00-\x1F\x7F]/u', '', mb_substr($experiment->title ?? '', 0, 200)) ?? '';
        $safeThesis = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $experiment->thesis ?? '') ?? '';
        $userPrompt = "Score this experiment thesis:\n\nTitle: {$safeTitle}\nThesis: {$safeThesis}\nSignal: ".json_encode($signalPayload, JSON_UNESCAPED_UNICODE).$memoryContext;

        $dependencyContext = $experiment->constraints['dependency_context'] ?? [];
        if (! empty($dependencyContext)) {
            $userPrompt .= "\n\n--- Context from predecessor projects ---";
            foreach ($dependencyContext as $alias => $data) {
                $userPrompt .= "\n\n[{$alias}] Project: {$data['project_title']} (Run #{$data['run_number']})";
                foreach ($data['artifacts'] ?? [] as $artifact) {
                    $userPrompt .= "\nArtifact [{$artifact['type']}] {$artifact['name']}:\n{$artifact['content']}";
                }
                if (! empty($data['stage_outputs'])) {
                    $userPrompt .= "\nStage outputs: ".json_encode($data['stage_outputs'], JSON_UNESCAPED_UNICODE);
                }
            }
        }

        $request = new AiRequestDTO(
            provider: $llm['provider'],
            model: $llm['model'],
            systemPrompt: $rubric['system_prompt'],
            userPrompt: $userPrompt,
            maxTokens: 1024,
            userId: $experiment->user_id,
            experimentId: $experiment->id,
            experimentStageId: $stage->id,
            purpose: 'stage:scoring',
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

        // Decide next transition. The rubric (resolved by signal type / track)
        // sets the default threshold; an explicit per-experiment score_threshold
        // still overrides it.
        $threshold = $experiment->constraints['score_threshold'] ?? $rubric['threshold'];

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

    /**
     * Resolve the scoring rubric via a priority chain:
     * signal source_type → experiment track → default. Each rubric supplies the
     * scoring question (system_prompt) and a default threshold, so different
     * signal types are judged by the right criteria (e.g. business potential vs
     * bug severity). The per-experiment constraints.score_threshold (applied by
     * the caller) still overrides the threshold.
     *
     * recommended_track / scoring_details are display-only, so a rubric may use
     * its own vocabulary without downstream coupling. Falls back to the built-in
     * business prompt + 0.3 when config is absent.
     *
     * @return array{system_prompt: string, threshold: float}
     */
    private function resolveScoringRubric(Experiment $experiment, ?Signal $signal): array
    {
        $rubrics = config('experiments.scoring_rubrics', []);
        $key = 'default';

        $source = $signal?->source_type;
        $bySource = config('experiments.scoring_rubric_by_source', []);
        if (is_string($source) && isset($bySource[$source], $rubrics[$bySource[$source]])) {
            $key = $bySource[$source];
        } elseif (($track = $experiment->track?->value) !== null && isset($rubrics[$track])) {
            $key = $track;
        }

        $rubric = is_array($rubrics[$key] ?? null) ? $rubrics[$key] : [];

        return [
            'system_prompt' => is_string($rubric['system_prompt'] ?? null) ? $rubric['system_prompt'] : self::FALLBACK_PROMPT,
            'threshold' => (float) ($rubric['threshold'] ?? 0.3),
        ];
    }
}
