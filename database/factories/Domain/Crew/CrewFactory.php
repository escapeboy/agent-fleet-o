<?php

namespace Database\Factories\Domain\Crew;

use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CrewFactory extends Factory
{
    protected $model = Crew::class;

    public function definition(): array
    {
        $name = fake()->words(3, true).' Crew';

        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'process_type' => CrewProcessType::Sequential,
            'max_task_iterations' => 3,
            'quality_threshold' => 0.7,
            'status' => CrewStatus::Active,
            'settings' => [],
        ];
    }
}
