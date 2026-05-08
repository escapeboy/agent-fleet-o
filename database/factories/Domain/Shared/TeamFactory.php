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
        // Faker's company() pool is small enough that two `Team::factory()->create()`
        // calls in the same test (e.g. BitbucketToolsTest::test_read_file_does_not_leak_other_team_credential)
        // can collide on the resulting slug, hitting the UNIQUE constraint on
        // teams.slug. Append a random suffix so two factory calls in the same
        // process always produce distinct slugs without surprising tests that
        // assert on the human-readable name.
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'owner_id' => User::factory(),
            'settings' => [],
        ];
    }
}
