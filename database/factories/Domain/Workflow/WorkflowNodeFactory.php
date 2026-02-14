<?php

namespace Database\Factories\Domain\Workflow;

use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowNodeFactory extends Factory
{
    protected $model = WorkflowNode::class;

    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'type' => WorkflowNodeType::Agent,
            'label' => fake()->words(3, true),
            'position_x' => fake()->numberBetween(0, 800),
            'position_y' => fake()->numberBetween(0, 600),
            'config' => [],
            'order' => 0,
        ];
    }

    public function start(): static
    {
        return $this->state([
            'type' => WorkflowNodeType::Start,
            'label' => 'Start',
        ]);
    }

    public function end(): static
    {
        return $this->state([
            'type' => WorkflowNodeType::End,
            'label' => 'End',
        ]);
    }
}
