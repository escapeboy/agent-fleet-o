<?php

namespace Database\Factories\Domain\Audience;

use App\Domain\Audience\Models\Audience;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AudienceFactory extends Factory
{
    protected $model = Audience::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'team_id' => Team::factory(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name).'-'.Str::random(4),
            'description' => fake()->sentence(),
            'topic' => fake()->randomElement(['newsletter', 'product_updates', 'announcements', null]),
        ];
    }
}
