<?php

namespace Database\Factories\Domain\Marketplace;

use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MarketplaceListingFactory extends Factory
{
    protected $model = MarketplaceListing::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'team_id' => Team::factory(),
            'published_by' => User::factory(),
            'type' => 'skill',
            'listable_id' => fake()->uuid(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->paragraph(),
            'readme' => fake()->paragraphs(3, true),
            'category' => fake()->randomElement(['marketing', 'analytics', 'automation', 'ai']),
            'tags' => [fake()->word(), fake()->word()],
            'status' => MarketplaceStatus::Published,
            'visibility' => ListingVisibility::Public,
            'version' => '1.0.0',
            'configuration_snapshot' => [],
            'install_count' => 0,
            'avg_rating' => 0,
            'review_count' => 0,
        ];
    }
}
