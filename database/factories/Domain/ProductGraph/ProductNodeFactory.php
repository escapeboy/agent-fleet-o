<?php

namespace Database\Factories\Domain\ProductGraph;

use App\Domain\ProductGraph\Enums\NodeStatus;
use App\Domain\ProductGraph\Enums\NodeType;
use App\Domain\ProductGraph\Models\ProductNode;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductNodeFactory extends Factory
{
    protected $model = ProductNode::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'team_id' => Team::factory(),
            'node_type' => NodeType::Feature,
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'status' => NodeStatus::Planned,
            'description' => fake()->sentence(),
            'tags' => [],
            'external_ref' => null,
            'metadata' => [],
        ];
    }

    public function type(NodeType $type): static
    {
        return $this->state(['node_type' => $type]);
    }

    public function status(NodeStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
