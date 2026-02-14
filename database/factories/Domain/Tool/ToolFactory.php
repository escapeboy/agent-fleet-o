<?php

namespace Database\Factories\Domain\Tool;

use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ToolFactory extends Factory
{
    protected $model = Tool::class;

    public function definition(): array
    {
        $name = fake()->words(2, true).' Tool';

        return [
            'team_id' => Team::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'type' => ToolType::McpStdio,
            'status' => ToolStatus::Active,
            'transport_config' => ['command' => 'echo', 'args' => ['hello']],
            'credentials' => [],
            'tool_definitions' => [],
            'settings' => [],
        ];
    }

    public function disabled(): static
    {
        return $this->state(['status' => ToolStatus::Disabled]);
    }

    public function builtIn(): static
    {
        return $this->state(['type' => ToolType::BuiltIn]);
    }
}
