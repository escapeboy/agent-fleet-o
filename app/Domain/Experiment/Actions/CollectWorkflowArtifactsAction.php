<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Services\ArtifactContentResolver;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CollectWorkflowArtifactsAction
{
    private const MAX_CONTENT_BYTES = 1_000_000; // 1 MB

    /**
     * Convert completed PlaybookStep outputs into Artifact + ArtifactVersion records.
     */
    public function execute(Experiment $experiment): Collection
    {
        $steps = PlaybookStep::where('experiment_id', $experiment->id)
            ->where('status', 'completed')
            ->whereNotNull('output')
            ->orderBy('order')
            ->get();

        if ($steps->isEmpty()) {
            return collect();
        }

        $artifacts = collect();
        $labelCounts = [];

        foreach ($steps as $step) {
            $content = $this->extractContent($step->output);

            if ($content === null || trim($content) === '') {
                continue;
            }

            if (mb_strlen($content) > self::MAX_CONTENT_BYTES) {
                $content = mb_substr($content, 0, self::MAX_CONTENT_BYTES)."\n\n[Content truncated — exceeded 1 MB limit]";
            }

            $type = $this->detectContentType($content);
            $baseLabel = $this->resolveStepLabel($step, $experiment);

            // Disambiguate duplicate labels
            $labelCounts[$baseLabel] = ($labelCounts[$baseLabel] ?? 0) + 1;
            $label = $labelCounts[$baseLabel] > 1
                ? "{$baseLabel} (Step {$step->order})"
                : $baseLabel;

            $artifact = Artifact::withoutGlobalScopes()->create([
                'team_id' => $experiment->team_id,
                'experiment_id' => $experiment->id,
                'type' => $type,
                'name' => $label,
                'current_version' => 1,
                'metadata' => [
                    'source' => 'workflow_step',
                    'step_id' => $step->id,
                    'step_order' => $step->order,
                    'agent_id' => $step->agent_id,
                    'workflow_node_id' => $step->workflow_node_id,
                ],
            ]);

            ArtifactVersion::withoutGlobalScopes()->create([
                'team_id' => $experiment->team_id,
                'artifact_id' => $artifact->id,
                'version' => 1,
                'content' => $content,
                'metadata' => [
                    'duration_ms' => $step->duration_ms,
                    'cost_credits' => $step->cost_credits,
                ],
            ]);

            $artifacts->push($artifact);
        }

        Log::info('CollectWorkflowArtifacts: Created artifacts from workflow steps', [
            'experiment_id' => $experiment->id,
            'artifacts_count' => $artifacts->count(),
            'steps_processed' => $steps->count(),
        ]);

        return $artifacts;
    }

    /**
     * Extract text content from a PlaybookStep output array.
     */
    private function extractContent(mixed $output): ?string
    {
        if (is_string($output)) {
            return $this->resolveFileReference($output);
        }

        if (! is_array($output)) {
            return null;
        }

        // Check for file_path key — agent may have written content to disk
        if (isset($output['file_path']) && is_string($output['file_path'])) {
            $fileContent = $this->readArtifactFile($output['file_path']);
            if ($fileContent !== null) {
                return $fileContent;
            }
        }

        // Try known keys in priority order
        foreach (['result', 'content', 'text', 'body', 'output'] as $key) {
            if (isset($output[$key]) && is_string($output[$key]) && trim($output[$key]) !== '') {
                return $this->resolveFileReference($output[$key]);
            }
        }

        // If the array has meaningful data but no recognized key, serialize it
        if (! empty($output)) {
            return json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return null;
    }

    /**
     * If content is a short stub referencing a file path, read the actual file.
     */
    private function resolveFileReference(string $content): string
    {
        // If content is substantial (>500 chars), it's real content, not a reference
        if (mb_strlen($content) > 500) {
            return $content;
        }

        // Look for storage/app/artifacts/ path references in the text
        if (preg_match('#(storage/app/artifacts/[^\s"\'<>]+)#', $content, $matches)) {
            $fileContent = $this->readArtifactFile($matches[1]);
            if ($fileContent !== null) {
                return $fileContent;
            }
        }

        return $content;
    }

    /**
     * Read an artifact file from disk given a relative or absolute path.
     */
    private function readArtifactFile(string $path): ?string
    {
        // Normalize to absolute path
        $fullPath = str_starts_with($path, '/') ? $path : base_path($path);

        if (! file_exists($fullPath) || ! is_readable($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);

        return ($content !== false && trim($content) !== '') ? $content : null;
    }

    /**
     * Detect content type using ArtifactContentResolver's sniffing logic.
     */
    private function detectContentType(string $content): string
    {
        // Use the content resolver's sniffing — pass an unknown type so it falls through to content detection
        return ArtifactContentResolver::category('unknown', $content);
    }

    /**
     * Resolve a human-readable label for a step's artifact.
     *
     * Priority: workflow node label → agent name → crew name → skill name → fallback
     */
    private function resolveStepLabel(PlaybookStep $step, Experiment $experiment): string
    {
        // 1. Workflow node label from graph snapshot
        if ($step->workflow_node_id && $experiment->constraints) {
            $nodes = $experiment->constraints['workflow_graph']['nodes'] ?? [];
            foreach ($nodes as $node) {
                if (($node['id'] ?? '') === $step->workflow_node_id) {
                    $label = $node['config']['label'] ?? $node['label'] ?? null;
                    if ($label && trim($label) !== '') {
                        return trim($label);
                    }
                    break;
                }
            }
        }

        // 2. Agent name
        if ($step->agent_id && $step->agent) {
            return $step->agent->name;
        }

        // 3. Crew name
        if ($step->crew_id && $step->crew) {
            return $step->crew->name;
        }

        // 4. Skill name
        if ($step->skill_id && $step->skill) {
            return $step->skill->name;
        }

        return "Step {$step->order}";
    }
}
