<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Shared\Models\TeamProviderCredential;

class TeamControllerTest extends ApiTestCase
{
    public function test_can_show_team(): void
    {
        $this->actingAsApiUser();

        $response = $this->getJson('/api/v1/team');

        $response->assertOk()
            ->assertJsonPath('data.id', $this->team->id)
            ->assertJsonPath('data.name', 'Test Team');
    }

    public function test_can_update_team(): void
    {
        $this->actingAsApiUser();

        $response = $this->putJson('/api/v1/team', [
            'name' => 'Updated Team Name',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Team Name');
    }

    public function test_can_list_members(): void
    {
        $this->actingAsApiUser();

        $response = $this->getJson('/api/v1/team/members');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.role', 'owner');
    }

    public function test_can_remove_member(): void
    {
        $this->actingAsApiUser();
        $member = $this->createTeamMember('member');

        $response = $this->deleteJson("/api/v1/team/members/{$member->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Member removed.']);
    }

    public function test_cannot_remove_owner(): void
    {
        $this->actingAsApiUser();

        $response = $this->deleteJson("/api/v1/team/members/{$this->user->id}");

        $response->assertStatus(403)
            ->assertJson(['message' => 'Cannot remove the team owner.']);
    }

    public function test_can_list_credentials(): void
    {
        $this->actingAsApiUser();

        TeamProviderCredential::create([
            'team_id' => $this->team->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/team/credentials');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider', 'openai');
    }

    public function test_can_store_credential(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/team/credentials', [
            'provider' => 'anthropic',
            'credentials' => ['api_key' => 'sk-ant-test'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.provider', 'anthropic');
    }

    public function test_can_create_api_token(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/team/tokens', [
            'name' => 'My API Token',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['token', 'name']]);
    }

    public function test_can_list_api_tokens(): void
    {
        $this->actingAsApiUser();

        // Create a token first
        $this->user->createToken('Test Token', ['*']);

        $response = $this->getJson('/api/v1/team/tokens');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_unauthenticated_cannot_access_team(): void
    {
        $response = $this->getJson('/api/v1/team');

        $response->assertStatus(401);
    }
}
