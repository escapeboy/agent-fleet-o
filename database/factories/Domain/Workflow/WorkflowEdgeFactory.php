<?php

namespace Database\Factories\Domain\Workflow;

use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowEdgeFactory extends Factory
{
    protected $model = WorkflowEdge::class;

    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'source_node_id' => WorkflowNode::factory(),
            'target_node_id' => WorkflowNode::factory(),
            'condition' => null,
            'label' => null,
            'is_default' => true,
            'sort_order' => 0,
        ];
    }
}
