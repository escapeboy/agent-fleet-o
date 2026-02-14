<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Skill\Models\Skill;

class MarketplaceControllerTest extends ApiTestCase
{
    private function createSkill(array $overrides = []): Skill
    {
        return Skill::create(array_merge([
            'team_id' => $this->team->id,
            'name' => 'Test Skill',
            'slug' => 'test-skill-'.uniqid(),
            'description' => 'A test skill',
            'type' => 'llm',
            'execution_type' => 'sync',
            'status' => 'active',
            'risk_level' => 'low',
            'input_schema' => [],
            'output_schema' => [],
            'configuration' => [],
            'cost_profile' => [],
            'safety_flags' => [],
            'requires_approval' => false,
            'current_version' => '1.0.0',
            'execution_count' => 0,
            'success_count' => 0,
            'avg_latency_ms' => 0,
        ], $overrides));
    }

    private function createListing(array $overrides = []): MarketplaceListing
    {
        $skill = $overrides['skill'] ?? $this->createSkill();
        unset($overrides['skill']);

        return MarketplaceListing::create(array_merge([
            'team_id' => $this->team->id,
            'published_by' => $this->user->id,
            'type' => 'skill',
            'listable_id' => $skill->id,
            'name' => 'Published Skill',
            'slug' => 'published-skill-'.uniqid(),
            'description' => 'A published skill listing',
            'status' => 'published',
            'visibility' => 'public',
            'version' => '1.0.0',
            'configuration_snapshot' => [
                'type' => 'llm',
                'input_schema' => [],
                'output_schema' => [],
                'configuration' => [],
                'system_prompt' => null,
                'risk_level' => 'low',
            ],
            'install_count' => 0,
            'avg_rating' => 0,
            'review_count' => 0,
        ], $overrides));
    }

    public function test_can_browse_marketplace(): void
    {
        $this->actingAsApiUser();
        $this->createListing(['name' => 'Listing One']);
        $this->createListing(['name' => 'Listing Two']);

        $response = $this->getJson('/api/v1/marketplace');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'slug', 'type', 'status']],
            ]);
    }

    public function test_can_filter_marketplace_by_type(): void
    {
        $this->actingAsApiUser();
        $this->createListing(['name' => 'Skill Listing', 'type' => 'skill']);
        $this->createListing(['name' => 'Agent Listing', 'type' => 'agent']);

        $response = $this->getJson('/api/v1/marketplace?filter[type]=skill');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Skill Listing');
    }

    public function test_can_show_listing(): void
    {
        $this->actingAsApiUser();
        $listing = $this->createListing();

        $response = $this->getJson("/api/v1/marketplace/{$listing->slug}");

        $response->assertOk()
            ->assertJsonPath('data.id', $listing->id)
            ->assertJsonPath('data.name', 'Published Skill');
    }

    public function test_can_publish_to_marketplace(): void
    {
        $this->actingAsApiUser();
        $skill = $this->createSkill();

        $response = $this->postJson('/api/v1/marketplace', [
            'type' => 'skill',
            'item_id' => $skill->id,
            'name' => 'My Published Skill',
            'description' => 'A skill for the marketplace',
            'category' => 'productivity',
            'tags' => ['ai', 'productivity'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'My Published Skill')
            ->assertJsonPath('data.type', 'skill');
    }

    public function test_can_install_listing(): void
    {
        $this->actingAsApiUser();
        $listing = $this->createListing();

        $response = $this->postJson("/api/v1/marketplace/{$listing->slug}/install");

        $response->assertStatus(201)
            ->assertJson(['message' => 'Listing installed successfully.']);

        $this->assertDatabaseHas('marketplace_installations', [
            'listing_id' => $listing->id,
            'team_id' => $this->team->id,
        ]);
    }

    public function test_can_submit_review(): void
    {
        $this->actingAsApiUser();
        $listing = $this->createListing();

        $response = $this->postJson("/api/v1/marketplace/{$listing->slug}/reviews", [
            'rating' => 5,
            'comment' => 'Great skill!',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.rating', 5);

        $this->assertDatabaseHas('marketplace_reviews', [
            'listing_id' => $listing->id,
            'rating' => 5,
        ]);
    }

    public function test_review_validates_rating(): void
    {
        $this->actingAsApiUser();
        $listing = $this->createListing();

        $response = $this->postJson("/api/v1/marketplace/{$listing->slug}/reviews", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rating']);
    }

    public function test_unauthenticated_cannot_browse_marketplace(): void
    {
        $response = $this->getJson('/api/v1/marketplace');

        $response->assertStatus(401);
    }
}
