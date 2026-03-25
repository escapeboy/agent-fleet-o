<?php

namespace App\Domain\Workflow\Executors;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Contracts\NodeExecutorInterface;
use App\Domain\Workflow\Models\WorkflowNode;

/**
 * Collects outputs from all completed predecessor steps and merges them.
 *
 * Config shape:
 * {
 *   "output_variable": "aggregated_results",
 *   "merge_strategy": "array"   // "array" | "concat" | "json_merge"
 * }
 *
 * Use after DynamicFork or parallel branches as a join point.
 * This node has zero LLM cost.
 */
class VariableAggregatorNodeExecutor implements NodeExecutorInterface
{
    use InterpolatesTemplates;

    /** Maximum serialized output size in bytes (1 MB) to prevent memory exhaustion. */
    private const MAX_OUTPUT_BYTES = 1_048_576;

    public function execute(WorkflowNode $node, PlaybookStep $step, Experiment $experiment): array
    {
        $config = $this->parseConfig($node->config);
        $mergeStrategy = $config['merge_strategy'] ?? 'array';
        $outputVariable = $config['output_variable'] ?? 'aggregated_results';

        // Collect outputs from all predecessor steps (completed before this one, in order)
        $predecessorOutputs = PlaybookStep::where('experiment_id', $experiment->id)
            ->where('status', 'completed')
            ->where('order', '<', $step->order)
            ->orderBy('order')
            ->get()
            ->filter(fn (PlaybookStep $s) => is_array($s->output) && ! empty($s->output))
            ->map(fn (PlaybookStep $s) => $s->output)
            ->values()
            ->all();

        $merged = $this->merge($predecessorOutputs, $mergeStrategy);

        // Guard against excessive output that could exhaust memory when stored.
        if (strlen(json_encode($merged) ?: '') > self::MAX_OUTPUT_BYTES) {
            throw new \RuntimeException(
                'VariableAggregator: aggregated output exceeds the '.
                (self::MAX_OUTPUT_BYTES / 1_048_576).' MB limit. Reduce predecessor output sizes.',
            );
        }

        return [
            $outputVariable => $merged,
            'aggregated_results' => $merged,
            'count' => count($predecessorOutputs),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $outputs
     */
    private function merge(array $outputs, string $strategy): mixed
    {
        return match ($strategy) {
            'concat' => implode("\n\n", array_map(
                fn ($o) => is_string($o['text'] ?? null) ? $o['text'] : json_encode($o),
                $outputs,
            )),
            'json_merge' => array_merge_recursive(...($outputs ?: [[]])),
            default => $outputs, // 'array' — return as-is
        };
    }
}
