<?php

namespace Tests\Feature\Marketplace;

use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Enums\RiskLevel;
use App\Models\User;
use Database\Seeders\MarketplaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceSeederRiskLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_skill_listings_use_valid_risk_levels(): void
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Seed Team',
            'slug' => 'seed-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => 'owner']);

        $this->seed(MarketplaceSeeder::class);

        $skills = MarketplaceListing::withoutGlobalScopes()->where('type', 'skill')->get();
        $this->assertNotEmpty($skills);

        foreach ($skills as $listing) {
            $snapshot = $listing->configuration_snapshot ?? [];
            if (! array_key_exists('risk_level', $snapshot)) {
                continue;
            }

            $this->assertNotNull(
                RiskLevel::tryFrom($snapshot['risk_level']),
                "Listing {$listing->slug} has invalid risk_level '{$snapshot['risk_level']}'",
            );
        }
    }
}
