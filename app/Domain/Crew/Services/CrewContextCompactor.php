<?php

namespace App\Domain\Crew\Services;

use App\Domain\Assistant\DTOs\MemorySummarySchema;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

/**
 * Compresses accumulated crew execution context into a structured summary.
 *
 * When a crew execution has many completed tasks, their outputs accumulate and
 * may exceed context windows for downstream agents. This compactor produces a
 * structured MemorySummarySchema from completed task outputs, preserving key
 * findings while staying within token budgets.
 */
class CrewContextCompactor
{
    private const MAX_CONTEXT_TOKENS = 8000;

    public function shouldCompact(CrewExecution $execution): bool
    {
        $completedOutputs = $execution->taskExecutions()
            ->whereNotNull('output')
            ->pluck('output');

        $estimatedTokens = $completedOutputs->sum(fn ($output) => (int) ceil(mb_strlen(json_encode($output)) / 4));

        return $estimatedTokens > self::MAX_CONTEXT_TOKENS;
    }

    public function compact(CrewExecution $execution): MemorySummarySchema
    {
        $completedTasks = $execution->taskExecutions()
            ->whereNotNull('output')
            ->orderBy('completed_at')
            ->get();

        if ($completedTasks->isEmpty()) {
            return new MemorySummarySchema(
                taskOverview: $execution->goal ?? 'No goal specified',
                currentState: 'No tasks completed yet.',
                keyDiscoveries: [],
                nextSteps: [],
                contextToPreserve: '',
            );
        }

        $schema = $this->synthesise($execution, $completedTasks);

        // Store compression in execution metadata
        $configSnapshot = $execution->config_snapshot ?? [];
        $configSnapshot['context_compression'] = [
            'compressed_at' => now()->toIso8601String(),
            'tasks_compressed' => $completedTasks->count(),
            'estimated_tokens' => $schema->estimateTokens(),
        ];
        $execution->update(['config_snapshot' => $configSnapshot]);

        Log::info('CrewContextCompactor: compressed crew context', [
            'execution_id' => $execution->id,
            'tasks_compressed' => $completedTasks->count(),
            'estimated_tokens' => $schema->estimateTokens(),
        ]);

        return $schema;
    }

    /**
     * Build a context string from completed tasks, with optional compression.
     */
    public function buildContext(CrewExecution $execution): string
    {
        if ($this->shouldCompact($execution)) {
            return $this->compact($execution)->toContextString();
        }

        // No compression needed — return raw task outputs
        return $execution->taskExecutions()
            ->whereNotNull('output')
            ->orderBy('completed_at')
            ->get()
            ->map(fn (CrewTaskExecution $t) => "Task [{$t->task_description}]: ".mb_substr(json_encode($t->output), 0, 1000))
            ->implode("\n\n");
    }

    private function synthesise(CrewExecution $execution, Collection $completedTasks): MemorySummarySchema
    {
        $taskSummaries = $completedTasks->map(function (CrewTaskExecution $task) {
            $output = is_array($task->output) ? json_encode($task->output) : (string) $task->output;

            return "Task: {$task->task_description}\nAgent: {$task->agent?->name}\nOutput: ".mb_substr($output, 0, 500);
        })->implode("\n---\n");

        $pendingTasks = $execution->taskExecutions()
            ->whereNull('output')
            ->pluck('task_description')
            ->implode(', ');

        $model = config('context_compaction.summarizer_model', 'anthropic/claude-haiku-4-5');
        [$modelProvider, $modelName] = array_pad(explode('/', $model, 2), 2, 'claude-haiku-4-5');

        $schemaJson = json_encode(MemorySummarySchema::jsonSchema(), JSON_PRETTY_PRINT);

        $systemPrompt = <<<'SYSTEM'
You are a crew execution context compressor. Synthesize completed task outputs into structured memory for downstream agents. Preserve specific findings, data, and entity references.
SYSTEM;

        $userPrompt = <<<PROMPT
Compress these crew task outputs into structured memory.

Goal: {$execution->goal}

Completed tasks ({$completedTasks->count()}):
{$taskSummaries}

Pending tasks: {$pendingTasks}

Output a JSON object matching this schema:
{$schemaJson}

Output ONLY valid JSON.
PROMPT;

        try {
            $response = Prism::text()
                ->using($modelProvider, $modelName)
                ->withSystemPrompt($systemPrompt)
                ->withPrompt($userPrompt)
                ->withMaxTokens(800)
                ->generate();

            $text = trim($response->text);

            if (str_starts_with($text, '```')) {
                $text = preg_replace('/^```(?:json)?\s*/', '', $text);
                $text = preg_replace('/\s*```$/', '', $text);
            }

            $data = json_decode($text, true);

            if (! is_array($data) || ! isset($data['task_overview'])) {
                return $this->fallback($execution, $completedTasks);
            }

            return MemorySummarySchema::fromArray($data);
        } catch (\Throwable $e) {
            Log::warning('CrewContextCompactor: LLM synthesis failed', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
            ]);

            return $this->fallback($execution, $completedTasks);
        }
    }

    private function fallback(CrewExecution $execution, Collection $completedTasks): MemorySummarySchema
    {
        return new MemorySummarySchema(
            taskOverview: mb_substr($execution->goal ?? 'Crew execution', 0, 500),
            currentState: "{$completedTasks->count()} tasks completed.",
            keyDiscoveries: $completedTasks->map(
                fn (CrewTaskExecution $t) => mb_substr($t->task_description.': '.json_encode($t->output), 0, 200),
            )->slice(0, 10)->values()->all(),
            nextSteps: [],
            contextToPreserve: '',
        );
    }
}
