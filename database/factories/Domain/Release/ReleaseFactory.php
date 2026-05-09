<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Release;

use App\Domain\Release\Enums\ReleaseStatus;
use App\Domain\Release\Models\Release;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ReleaseFactory extends Factory
{
    protected $model = Release::class;

    public function definition(): array
    {
        $name = fake()->words(2, true).' Release';

        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'version' => 'v'.fake()->numberBetween(1, 9).'.'.fake()->numberBetween(0, 9),
            'notes' => fake()->sentence(),
            'status' => ReleaseStatus::Draft,
            'metadata' => [],
        ];
    }

    public function published(): static
    {
        return $this->state([
            'status' => ReleaseStatus::Published,
            'share_token' => Str::random(48),
            'published_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state([
            'status' => ReleaseStatus::Archived,
            'archived_at' => now(),
        ]);
    }
}
