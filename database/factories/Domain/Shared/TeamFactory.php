<?php

namespace Database\Factories\Domain\Shared;

use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'owner_id' => User::factory(),
            'settings' => [],
        ];
    }
}
