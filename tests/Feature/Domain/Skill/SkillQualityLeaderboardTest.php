<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkillQualityLeaderboardTest extends TestCase
{
    use RefreshDatabase;

    private function listing(Team $team, array $overrides = []): MarketplaceListing
    {
        return MarketplaceListing::factory()->create(array_merge([
            'team_id' => $team->id,
            'type' => 'skill',
            'status' => MarketplaceStatus::Published,
            'visibility' => ListingVisibility::Public,
            'community_quality_score' => 0.9,
        ], $overrides));
    }

    public function test_leaderboard_renders_published_public_skills_without_auth(): void
    {
        $team = Team::factory()->create();
        $this->listing($team, ['name' => 'Stellar Summarizer', 'community_quality_score' => 0.95]);

        $response = $this->get('/skills/quality');

        $response->assertOk();
        $response->assertSee('Stellar Summarizer');
        $response->assertSee('Skill Quality Leaderboard');
    }

    public function test_unpublished_and_private_listings_are_excluded(): void
    {
        $team = Team::factory()->create();
        $this->listing($team, ['name' => 'Draft Skill', 'status' => MarketplaceStatus::Draft]);
        $this->listing($team, ['name' => 'Team Private Skill', 'visibility' => ListingVisibility::Team]);
        $this->listing($team, ['name' => 'Visible Skill']);

        $response = $this->get('/skills/quality');

        $response->assertOk();
        $response->assertSee('Visible Skill');
        $response->assertDontSee('Draft Skill');
        $response->assertDontSee('Team Private Skill');
    }
}
