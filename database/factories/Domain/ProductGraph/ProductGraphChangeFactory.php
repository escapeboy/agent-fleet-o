<?php

namespace Database\Factories\Domain\ProductGraph;

use App\Domain\ProductGraph\Enums\ChangeStatus;
use App\Domain\ProductGraph\Enums\ChangeType;
use App\Domain\ProductGraph\Models\ProductGraphChange;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductGraphChangeFactory extends Factory
{
    protected $model = ProductGraphChange::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'change_type' => ChangeType::CreateNode,
            'target_id' => null,
            'payload' => [
                'node_type' => 'feature',
                'name' => fake()->unique()->words(2, true),
                'status' => 'planned',
            ],
            'status' => ChangeStatus::Pending,
            'proposed_by_label' => 'agent:test',
        ];
    }
}
