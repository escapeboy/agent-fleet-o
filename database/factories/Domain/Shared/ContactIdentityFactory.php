<?php

namespace Database\Factories\Domain\Shared;

use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactIdentityFactory extends Factory
{
    protected $model = ContactIdentity::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'display_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'metadata' => [],
        ];
    }
}
