<?php

namespace App\Domain\Crew\Actions;

use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Experiment\Services\ArtifactContentResolver;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CollectCrewArtifactsAction
{
    private const MAX_CONTENT_BYTES = 1_000_000; // 1 MB

    /**
     * Convert completed CrewTaskExecution outputs into Artifact + ArtifactVersion records.
     */
    public function execute(CrewExecution $execution): Collection
    {
        // Idempotent: skip if artifacts already exist
        if (Artifact::withoutGlobalScopes()->where('crew_execution_id', $execution->id)->exists()) {
            return collect();
        }

        $teamId = $execution->crew->team_id ?? $execution->team_id;
        $artifacts = collect();

        // Collect individual task artifacts
        $tasks = $execution->taskExecutions()
            ->where('status', CrewTaskStatus::Validated)
            ->whereNotNull('output')
            ->orderBy('sort_order')
            ->get();

        $labelCounts = [];

        foreach ($tasks as $task) {
            $content = $this->extractContent($task->output);

            if ($content === null || trim($content) === '') {
                continue;
            }

            if (mb_strlen($content) > self::MAX_CONTENT_BYTES) {
                $content = mb_substr($content, 0, self::MAX_CONTENT_BYTES)."\n\n[Content truncated — exceeded 1 MB limit]";
            }

            $type = $this->detectContentType($content);
            $baseLabel = $task->title ?: "Task {$task->sort_order}";

            $labelCounts[$baseLabel] = ($labelCounts[$baseLabel] ?? 0) + 1;
            $label = $labelCounts[$baseLabel] > 1
                ? "{$baseLabel} (Task {$task->sort_order})"
                : $baseLabel;

            $artifact = Artifact::withoutGlobalScopes()->create([
                'team_id' => $teamId,
                'crew_execution_id' => $execution->id,
                'type' => $type,
                'name' => $label,
                'current_version' => 1,
                'metadata' => [
                    'source' => 'crew_task',
                    'task_execution_id' => $task->id,
                    'agent_id' => $task->agent_id,
                    'sort_order' => $task->sort_order,
                    'qa_score' => $task->qa_score,
                ],
            ]);

            ArtifactVersion::withoutGlobalScopes()->create([
                'team_id' => $teamId,
                'artifact_id' => $artifact->id,
                'version' => 1,
                'content' => $content,
                'metadata' => [
                    'duration_ms' => $task->duration_ms,
                    'cost_credits' => $task->cost_credits,
                ],
            ]);

            $artifacts->push($artifact);
        }

        // Collect the synthesized final output as a separate artifact
        if ($execution->final_output) {
            $finalContent = $this->extractContent($execution->final_output);

            if ($finalContent !== null && trim($finalContent) !== '') {
                if (mb_strlen($finalContent) > self::MAX_CONTENT_BYTES) {
                    $finalContent = mb_substr($finalContent, 0, self::MAX_CONTENT_BYTES)."\n\n[Content truncated — exceeded 1 MB limit]";
                }

                $artifact = Artifact::withoutGlobalScopes()->create([
                    'team_id' => $teamId,
                    'crew_execution_id' => $execution->id,
                    'type' => $this->detectContentType($finalContent),
                    'name' => 'Final Synthesis',
                    'current_version' => 1,
                    'metadata' => [
                        'source' => 'crew_synthesis',
                        'quality_score' => $execution->quality_score,
                    ],
                ]);

                ArtifactVersion::withoutGlobalScopes()->create([
                    'team_id' => $teamId,
                    'artifact_id' => $artifact->id,
                    'version' => 1,
                    'content' => $finalContent,
                    'metadata' => [
                        'total_cost_credits' => $execution->total_cost_credits,
                        'duration_ms' => $execution->duration_ms,
                    ],
                ]);

                $artifacts->push($artifact);
            }
        }

        Log::info('CollectCrewArtifacts: Created artifacts from crew execution', [
            'crew_execution_id' => $execution->id,
            'artifacts_count' => $artifacts->count(),
            'tasks_processed' => $tasks->count(),
        ]);

        return $artifacts;
    }

    private function extractContent(mixed $output): ?string
    {
        if (is_string($output)) {
            return $output;
        }

        if (! is_array($output)) {
            return null;
        }

        foreach (['result', 'content', 'text', 'body', 'output'] as $key) {
            if (isset($output[$key]) && is_string($output[$key]) && trim($output[$key]) !== '') {
                return $output[$key];
            }
        }

        if (! empty($output)) {
            return json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return null;
    }

    private function detectContentType(string $content): string
    {
        return ArtifactContentResolver::category('unknown', $content);
    }
}
