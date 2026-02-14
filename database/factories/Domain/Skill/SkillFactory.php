<?php

namespace Database\Factories\Domain\Skill;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Enums\ExecutionType;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SkillFactory extends Factory
{
    protected $model = Skill::class;

    public function definition(): array
    {
        $name = fake()->words(3, true).' Skill';

        return [
            'team_id' => Team::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'type' => SkillType::Llm,
            'execution_type' => ExecutionType::Sync,
            'status' => SkillStatus::Active,
            'risk_level' => RiskLevel::Low,
            'input_schema' => [],
            'output_schema' => [],
            'configuration' => [],
            'cost_profile' => [],
            'safety_flags' => [],
            'current_version' => 1,
            'requires_approval' => false,
            'system_prompt' => fake()->paragraph(),
            'execution_count' => 0,
            'success_count' => 0,
            'avg_latency_ms' => 0,
        ];
    }
}
