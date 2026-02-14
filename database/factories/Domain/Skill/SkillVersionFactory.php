<?php

namespace Database\Factories\Domain\Skill;

use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SkillVersionFactory extends Factory
{
    protected $model = SkillVersion::class;

    public function definition(): array
    {
        return [
            'skill_id' => Skill::factory(),
            'version' => 1,
            'input_schema' => [],
            'output_schema' => [],
            'configuration' => [],
            'changelog' => fake()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
