<?php

namespace App\Domain\Memory\Listeners;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Memory\Actions\StoreMemoryAction;
use App\Domain\Project\Models\ProjectRun;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class FlushAgentMemoryOnCompletion implements ShouldQueue
{
    public string $queue = 'ai-calls';

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly StoreMemoryAction $storeMemory,
    ) {}

    public function handle(ExperimentTransitioned $event): void
    {
        if (! config('memory.auto_flush_enabled', true)) {
            return;
        }

        $experiment = $event->experiment;

        if (! $experiment->workflow_id) {
            return;
        }

        if ($event->toState !== ExperimentStatus::Completed) {
            return;
        }

        $agentId = $experiment->agent_id;
        if (! $agentId) {
            return;
        }

        try {
            $summary = $this->gatherExecutionSummary($experiment);

            if (empty($summary)) {
                return;
            }

            $prompt = $this->buildFlushPrompt($summary);

            $llm = $this->resolveLlm($experiment);

            $request = new AiRequestDTO(
                provider: $llm['provider'],
                model: $llm['model'],
                systemPrompt: 'You are a memory curator. Extract important facts, decisions, and learnings from the execution summary. Return ONLY valid JSON (no markdown, no code fences): an array of objects with key (string, short snake_case identifier), content (string, what to remember), importance (float 0.0-1.0).',
                userPrompt: $prompt,
                teamId: $experiment->team_id,
                maxTokens: 2048,
                purpose: 'memory_auto_flush',
                temperature: 0.3,
            );

            $response = $this->gateway->complete($request);
            $this->parseAndSaveMemories($response->content, $agentId, $experiment);
        } catch (\Throwable $e) {
            Log::warning('FlushAgentMemoryOnCompletion: Failed', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function gatherExecutionSummary($experiment): array
    {
        $summary = [
            'experiment_id' => $experiment->id,
            'title' => $experiment->title,
            'thesis' => $experiment->thesis,
        ];

        $steps = $experiment->playbookSteps()
            ->whereNotNull('output')
            ->orderBy('sort_order')
            ->get(['title', 'output', 'status', 'error_message', 'started_at', 'completed_at']);

        if ($steps->isEmpty()) {
            return [];
        }

        $summary['steps'] = $steps->map(fn ($step) => [
            'title' => $step->title,
            'status' => $step->status,
            'output_preview' => mb_substr(json_encode($step->output), 0, 500),
            'error' => $step->error_message,
            'duration_seconds' => $step->started_at && $step->completed_at
                ? $step->started_at->diffInSeconds($step->completed_at)
                : null,
        ])->toArray();

        return $summary;
    }

    private function buildFlushPrompt(array $summary): string
    {
        $summaryJson = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
        ## Execution Summary

        {$summaryJson}

        ## Task
        Extract the most important facts, decisions, and learnings from this completed execution.
        Focus on information that would be useful for future runs of similar workflows.
        Ignore trivial or transient details.
        PROMPT;
    }

    private function parseAndSaveMemories(string $content, string $agentId, $experiment): void
    {
        $parsed = json_decode($content, true);
        if (! $parsed) {
            $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($content));
            $parsed = json_decode($cleaned, true);
        }

        if (! is_array($parsed)) {
            return;
        }

        if (isset($parsed[0]) && is_array($parsed[0])) {
            $entries = $parsed;
        } else {
            $entries = $parsed['memories'] ?? $parsed['entries'] ?? [];
        }

        $projectId = ProjectRun::where('experiment_id', $experiment->id)
            ->where('team_id', $experiment->team_id)
            ->value('project_id');
        $saved = 0;
        $maxEntries = 20;

        foreach ($entries as $entry) {
            if ($saved >= $maxEntries) {
                break;
            }

            if (empty($entry['content'])) {
                continue;
            }

            $this->storeMemory->execute(
                teamId: $experiment->team_id,
                agentId: $agentId,
                content: $entry['content'],
                sourceType: 'auto-flush',
                projectId: $projectId,
                sourceId: $experiment->id,
                metadata: [
                    'key' => $entry['key'] ?? null,
                    'experiment_id' => $experiment->id,
                ],
                importance: (float) ($entry['importance'] ?? 0.5),
                tags: ['auto-flush'],
            );
            $saved++;
        }

        if ($saved > 0) {
            Log::info("FlushAgentMemoryOnCompletion: Saved {$saved} memories for agent {$agentId} from experiment {$experiment->id}");
        }
    }

    private function resolveLlm($experiment): array
    {
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
