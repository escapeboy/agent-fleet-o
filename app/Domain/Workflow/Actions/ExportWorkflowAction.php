<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Models\Workflow;
use Symfony\Component\Yaml\Yaml;

class ExportWorkflowAction
{
    /**
     * Export a workflow to a portable v2 snapshot (JSON or YAML).
     */
    public function execute(Workflow $workflow, string $format = 'json'): array|string
    {
        $nodes = $workflow->nodes()->with(['agent:id,name,role', 'skill:id,name', 'crew:id,name'])->get();
        $edges = $workflow->edges()->orderBy('sort_order')->get();

        $workflowData = [
            'name' => $workflow->name,
            'description' => $workflow->description,
            'max_loop_iterations' => $workflow->max_loop_iterations,
            'estimated_cost_credits' => $workflow->estimated_cost_credits,
            'settings' => $workflow->settings ?? [],
            'node_count' => $nodes->count(),
            'agent_node_count' => $nodes->where('type.value', 'agent')->count(),
        ];

        $nodesData = $nodes->map(fn ($n) => [
            'id' => $n->id,
            'type' => $n->type->value,
            'label' => $n->label,
            'position_x' => $n->position_x,
            'position_y' => $n->position_y,
            'config' => $n->config ?? [],
            'order' => $n->order,
            'agent_id' => $n->agent_id,
            'skill_id' => $n->skill_id,
            'crew_id' => $n->crew_id,
            'agent_hint' => $n->agent ? ['name' => $n->agent->name, 'role' => $n->agent->role] : null,
            'skill_hint' => $n->skill ? ['name' => $n->skill->name] : null,
            'crew_hint' => $n->crew ? ['name' => $n->crew->name] : null,
        ])->values()->toArray();

        $edgesData = $edges->map(fn ($e) => [
            'id' => $e->id,
            'source_node_id' => $e->source_node_id,
            'target_node_id' => $e->target_node_id,
            'condition' => $e->condition,
            'label' => $e->label,
            'is_default' => $e->is_default,
            'sort_order' => $e->sort_order,
            'case_value' => $e->case_value,
            'source_channel' => $e->source_channel,
            'target_channel' => $e->target_channel,
        ])->values()->toArray();

        $checksum = hash('sha256', json_encode([$workflowData, $nodesData, $edgesData]));

        $references = $this->collectReferences($nodes);

        $envelope = [
            'format_version' => '2.0',
            'generator' => 'fleetq',
            'generator_version' => '1.1.0',
            'exported_at' => now()->toIso8601String(),
            'checksum' => $checksum,
            'workflow' => $workflowData,
            'nodes' => $nodesData,
            'edges' => $edgesData,
            'references' => $references,
        ];

        if ($format === 'yaml') {
            return Yaml::dump($envelope, 10, 2);
        }

        return $envelope;
    }

    /**
     * Collect name/type references for agents, skills, and crews used in the workflow.
     */
    private function collectReferences($nodes): array
    {
        $agents = [];
        $skills = [];
        $crews = [];

        foreach ($nodes as $node) {
            if ($node->agent) {
                $agents[$node->agent_id] = [
                    'name' => $node->agent->name,
                    'type' => 'agent',
                ];
            }
            if ($node->skill) {
                $skills[$node->skill_id] = [
                    'name' => $node->skill->name,
                    'type' => 'skill',
                ];
            }
            if ($node->crew) {
                $crews[$node->crew_id] = [
                    'name' => $node->crew->name,
                    'type' => 'crew',
                ];
            }
        }

        return [
            'agents' => array_values($agents),
            'skills' => array_values($skills),
            'crews' => array_values($crews),
        ];
    }
}
