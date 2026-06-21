<?php

namespace Database\Factories\Domain\ProductGraph;

use App\Domain\ProductGraph\Enums\EdgeType;
use App\Domain\ProductGraph\Models\ProductEdge;
use App\Domain\ProductGraph\Models\ProductNode;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductEdgeFactory extends Factory
{
    protected $model = ProductEdge::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'source_node_id' => fn (array $attributes) => ProductNode::factory()->create(['team_id' => $attributes['team_id']]),
            'target_node_id' => fn (array $attributes) => ProductNode::factory()->create(['team_id' => $attributes['team_id']]),
            'edge_type' => EdgeType::DependsOn,
            'description' => null,
            'metadata' => [],
        ];
    }

    public function edgeType(EdgeType $type): static
    {
        return $this->state(['edge_type' => $type]);
    }
}
