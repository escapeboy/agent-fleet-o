<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Workflow\Enums\WorkflowNodeType;

class NodeTypeCompatibility
{
    /**
     * Check type compatibility between an output port type and an input port type.
     */
    public static function isCompatible(string $outputType, string $inputType): bool
    {
        if ($inputType === 'any' || $outputType === 'any') {
            return true;
        }

        // Passthrough must be resolved by caller via resolvePassthroughType()
        if ($outputType === 'passthrough') {
            return true;
        }

        $outputTypes = explode('|', $outputType);
        $inputTypes = explode('|', $inputType);

        return count(array_intersect($outputTypes, $inputTypes)) > 0;
    }

    /**
     * Resolve passthrough chains by walking backwards through predecessors
     * in topological order until a concrete type is found.
     *
     * @param  string  $nodeId  The node whose output type to resolve.
     * @param  array<string, array{type: WorkflowNodeType}>  $nodeMap  node_id => ['type' => WorkflowNodeType, ...]
     * @param  array<string, list<object|array>>  $edgesByTarget  target_node_id => [edge, ...]
     */
    public static function resolvePassthroughType(
        string $nodeId,
        array $nodeMap,
        array $edgesByTarget,
    ): string {
        $visited = [];
        $currentId = $nodeId;

        while ($currentId) {
            if (in_array($currentId, $visited, true)) {
                return 'any'; // cycle guard
            }
            $visited[] = $currentId;

            $node = $nodeMap[$currentId] ?? null;
            if (! $node) {
                return 'any';
            }

            $schema = $node['type']->portSchema();
            $outputType = $schema['outputs'][0]['type'] ?? 'any';

            if ($outputType !== 'passthrough') {
                return $outputType;
            }

            // Walk back to first predecessor
            $inEdges = $edgesByTarget[$currentId] ?? [];
            if (empty($inEdges)) {
                return 'any';
            }

            $firstEdge = $inEdges[0];
            $currentId = is_object($firstEdge)
                ? ($firstEdge->source_node_id ?? null)
                : ($firstEdge['source_node_id'] ?? null);
        }

        return 'any';
    }
}
