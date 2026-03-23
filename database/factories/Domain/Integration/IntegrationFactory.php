<?php

namespace Database\Factories\Domain\Integration;

use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class IntegrationFactory extends Factory
{
    protected $model = Integration::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'driver' => fake()->randomElement(['github', 'slack', 'hubspot', 'linkedin']),
            'name' => fake()->words(2, true).' Integration',
            'credential_id' => null,
            'status' => IntegrationStatus::Active,
            'config' => [],
            'meta' => [],
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => IntegrationStatus::Active]);
    }

    public function disconnected(): static
    {
        return $this->state(['status' => IntegrationStatus::Disconnected]);
    }
}
