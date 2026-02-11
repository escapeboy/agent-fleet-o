<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillVersion;

class SkillControllerTest extends ApiTestCase
{
    private function createSkill(array $overrides = []): Skill
    {
        return Skill::create(array_merge([
            'team_id' => $this->team->id,
            'name' => 'Test Skill',
            'slug' => 'test-skill',
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

    public function test_can_list_skills(): void
    {
        $this->actingAsApiUser();
        $this->createSkill(['name' => 'Skill One', 'slug' => 'skill-one']);
        $this->createSkill(['name' => 'Skill Two', 'slug' => 'skill-two']);

        $response = $this->getJson('/api/v1/skills');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'type', 'status']],
            ]);
    }

    public function test_can_filter_skills_by_type(): void
    {
        $this->actingAsApiUser();
        $this->createSkill(['name' => 'LLM Skill', 'slug' => 'llm-skill', 'type' => 'llm']);
        $this->createSkill(['name' => 'Rule Skill', 'slug' => 'rule-skill', 'type' => 'rule']);

        $response = $this->getJson('/api/v1/skills?filter[type]=llm');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'LLM Skill');
    }

    public function test_can_show_skill(): void
    {
        $this->actingAsApiUser();
        $skill = $this->createSkill();

        $response = $this->getJson("/api/v1/skills/{$skill->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $skill->id)
            ->assertJsonPath('data.name', 'Test Skill');
    }

    public function test_can_create_skill(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/skills', [
            'name' => 'New Skill',
            'type' => 'llm',
            'description' => 'A new LLM skill',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'New Skill')
            ->assertJsonPath('data.type', 'llm');

        $this->assertDatabaseHas('skills', ['name' => 'New Skill']);
    }

    public function test_create_skill_validates_required_fields(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/skills', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'type']);
    }

    public function test_can_update_skill(): void
    {
        $this->actingAsApiUser();
        $skill = $this->createSkill();

        $response = $this->putJson("/api/v1/skills/{$skill->id}", [
            'name' => 'Updated Skill',
            'description' => 'Updated description',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Skill');
    }

    public function test_can_delete_skill(): void
    {
        $this->actingAsApiUser();
        $skill = $this->createSkill();

        $response = $this->deleteJson("/api/v1/skills/{$skill->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Skill deleted.']);

        $this->assertSoftDeleted('skills', ['id' => $skill->id]);
    }

    public function test_can_list_skill_versions(): void
    {
        $this->actingAsApiUser();
        $skill = $this->createSkill();

        SkillVersion::create([
            'skill_id' => $skill->id,
            'version' => '1.0.0',
            'input_schema' => [],
            'output_schema' => [],
            'configuration' => [],
            'changelog' => 'Initial version',
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/skills/{$skill->id}/versions");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.version', '1.0.0');
    }

    public function test_unauthenticated_cannot_list_skills(): void
    {
        $response = $this->getJson('/api/v1/skills');

        $response->assertStatus(401);
    }
}
