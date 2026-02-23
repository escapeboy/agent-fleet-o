<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ImportWorkflowAction
{
    /**
     * Import a workflow from a JSON snapshot created by ExportWorkflowAction.
     *
     * @throws \InvalidArgumentException
     */
    public function execute(
        array $data,
        string $teamId,
        string $userId,
        ?string $nameOverride = null,
    ): Workflow {
        $this->validate($data);

        return DB::transaction(function () use ($data, $teamId, $userId, $nameOverride) {
            $wf = $data['workflow'];
            $name = $nameOverride ?? ($wf['name'] ?? 'Imported Workflow');

            $workflow = Workflow::create([
                'team_id' => $teamId,
                'user_id' => $userId,
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::random(6),
                'description' => $wf['description'] ?? null,
                'status' => WorkflowStatus::Draft,
                'version' => 1,
                'max_loop_iterations' => $wf['max_loop_iterations'] ?? 5,
                'estimated_cost_credits' => $wf['estimated_cost_credits'] ?? null,
                'settings' => $wf['settings'] ?? [],
            ]);

            $nodeIdMap = [];

            foreach ($data['nodes'] ?? [] as $nodeData) {
                $type = WorkflowNodeType::tryFrom($nodeData['type'] ?? '') ?? WorkflowNodeType::Agent;

                $newNode = WorkflowNode::create([
                    'workflow_id' => $workflow->id,
                    'agent_id' => null,
                    'skill_id' => null,
                    'type' => $type,
                    'label' => $nodeData['label'] ?? 'Node',
                    'position_x' => $nodeData['position_x'] ?? 0,
                    'position_y' => $nodeData['position_y'] ?? 0,
                    'config' => $nodeData['config'] ?? [],
                    'order' => $nodeData['order'] ?? 0,
                ]);
                $nodeIdMap[$nodeData['id']] = $newNode->id;
            }

            foreach ($data['edges'] ?? [] as $edgeData) {
                $sourceId = $nodeIdMap[$edgeData['source_node_id']] ?? null;
                $targetId = $nodeIdMap[$edgeData['target_node_id']] ?? null;

                if ($sourceId && $targetId) {
                    WorkflowEdge::create([
                        'workflow_id' => $workflow->id,
                        'source_node_id' => $sourceId,
                        'target_node_id' => $targetId,
                        'condition' => $edgeData['condition'] ?? null,
                        'label' => $edgeData['label'] ?? null,
                        'is_default' => $edgeData['is_default'] ?? false,
                        'sort_order' => $edgeData['sort_order'] ?? 0,
                    ]);
                }
            }

            return $workflow;
        });
    }

    private function validate(array $data): void
    {
        $validator = Validator::make($data, [
            'version' => 'required|string',
            'workflow' => 'required|array',
            'nodes' => 'required|array',
            'edges' => 'required|array',
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException(
                'Invalid workflow export format: '.implode(', ', $validator->errors()->all()),
            );
        }
    }
}
