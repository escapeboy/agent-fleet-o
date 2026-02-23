<?php

namespace App\Domain\Memory\Listeners;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Memory\Actions\StoreMemoryAction;
use App\Domain\Project\Models\ProjectRun;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\Log;

class StoreExperimentLearnings
{
    public function __construct(
        private readonly StoreMemoryAction $storeMemory,
        private readonly AiGatewayInterface $gateway,
    ) {}

    public function handle(ExperimentTransitioned $event): void
    {
        if ($event->toState !== ExperimentStatus::Completed) {
            return;
        }

        if (! config('memory.enabled', true)) {
            return;
        }

        $experiment = $event->experiment;

        try {
            $stageOutputs = $this->gatherStageOutputs($experiment);

            if (empty($stageOutputs)) {
                return;
            }

            $learnings = $this->extractLearnings($experiment, $stageOutputs);

            if (empty($learnings)) {
                return;
            }

            $projectId = ProjectRun::where('experiment_id', $experiment->id)->value('project_id');

            foreach ($learnings as $learning) {
                $content = "## {$learning['title']}\n\n{$learning['content']}";
                $tags = array_merge(
                    $learning['tags'] ?? [],
                    [$experiment->track?->value ?? 'general'],
                );

                $this->storeMemory->execute(
                    teamId: $experiment->team_id,
                    agentId: $experiment->agent_id ?? 'team-knowledge',
                    content: $content,
                    sourceType: 'experiment',
                    projectId: $projectId,
                    sourceId: $experiment->id,
                    metadata: [
                        'experiment_id' => $experiment->id,
                        'experiment_title' => $experiment->title,
                        'learning_title' => $learning['title'],
                        'confidence' => $learning['confidence'] ?? 0.5,
                        'tags' => $tags,
                    ],
                );
            }

            Log::info("StoreExperimentLearnings: Stored ".count($learnings)." learnings from experiment {$experiment->id}");
        } catch (\Throwable $e) {
            Log::warning('StoreExperimentLearnings: Failed to extract learnings', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function gatherStageOutputs($experiment): array
    {
        $stages = $experiment->stages()
            ->whereIn('stage', [
                StageType::Scoring->value,
                StageType::Planning->value,
                StageType::Evaluation->value,
            ])
            ->whereNotNull('output_snapshot')
            ->orderBy('iteration')
            ->orderBy('created_at')
            ->get();

        $outputs = [];
        foreach ($stages as $stage) {
            $outputs[$stage->stage] = json_encode($stage->output_snapshot, JSON_UNESCAPED_UNICODE);
        }

        return $outputs;
    }

    private function extractLearnings($experiment, array $stageOutputs): array
    {
        $contextParts = [];
        foreach ($stageOutputs as $stage => $output) {
            $contextParts[] = ucfirst(str_replace('_', ' ', $stage)).": {$output}";
        }

        $llm = $this->resolveLlm($experiment);

        $request = new AiRequestDTO(
            provider: $llm['provider'],
            model: $llm['model'],
            systemPrompt: 'Extract 3-5 key learnings from this completed experiment. Each learning should be a reusable insight for future experiments. Return ONLY valid JSON (no markdown, no code fences): an array of objects with title (string, max 10 words), content (string, max 100 words), tags (array of max 3 strings), confidence (float 0.0-1.0).',
            userPrompt: "Experiment: {$experiment->title}\nThesis: {$experiment->thesis}\n\nStage outputs:\n".implode("\n\n", $contextParts),
            maxTokens: 1024,
            teamId: $experiment->team_id,
            experimentId: $experiment->id,
            purpose: 'learning_extraction',
            temperature: 0.3,
        );

        $response = $this->gateway->complete($request);

        $parsed = json_decode($response->content, true);
        if (! $parsed) {
            $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($response->content));
            $parsed = json_decode($cleaned, true);
        }

        if (! is_array($parsed)) {
            return [];
        }

        // Handle both [{...}] and {learnings: [{...}]} formats
        if (isset($parsed[0]) && is_array($parsed[0])) {
            return $parsed;
        }

        return $parsed['learnings'] ?? [];
    }

    private function resolveLlm($experiment): array
    {
        // Use experiment's configured LLM or fall back to platform default
        $config = $experiment->constraints ?? [];

        if (! empty($config['llm_provider']) && ! empty($config['llm_model'])) {
            return ['provider' => $config['llm_provider'], 'model' => $config['llm_model']];
        }

        return [
            'provider' => config('llm_pricing.default_provider', 'anthropic'),
            'model' => config('llm_pricing.default_model', 'claude-sonnet-4-5'),
        ];
    }
}
