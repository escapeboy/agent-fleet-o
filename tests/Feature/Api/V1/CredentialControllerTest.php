<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Credential\Models\Credential;

class CredentialControllerTest extends ApiTestCase
{
    private function createCredential(array $overrides = []): Credential
    {
        return Credential::create(array_merge([
            'team_id' => $this->team->id,
            'name' => 'Test Credential',
            'slug' => 'test-credential',
            'credential_type' => 'api_token',
            'status' => 'active',
            'secret_data' => ['token' => 'test-key-123'],
            'metadata' => [],
        ], $overrides));
    }

    public function test_can_list_credentials(): void
    {
        $this->actingAsApiUser();
        $this->createCredential(['name' => 'Cred One', 'slug' => 'cred-one']);
        $this->createCredential(['name' => 'Cred Two', 'slug' => 'cred-two']);

        $response = $this->getJson('/api/v1/credentials');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'status', 'credential_type']],
            ]);
    }

    public function test_can_filter_credentials_by_type(): void
    {
        $this->actingAsApiUser();
        $this->createCredential(['name' => 'API Token', 'slug' => 'api-token', 'credential_type' => 'api_token']);
        $this->createCredential(['name' => 'Basic Auth', 'slug' => 'basic-auth', 'credential_type' => 'basic_auth', 'secret_data' => ['username' => 'u', 'password' => 'p']]);

        $response = $this->getJson('/api/v1/credentials?filter[credential_type]=api_token');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'API Token');
    }

    public function test_can_show_credential(): void
    {
        $this->actingAsApiUser();
        $credential = $this->createCredential();

        $response = $this->getJson("/api/v1/credentials/{$credential->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $credential->id)
            ->assertJsonPath('data.name', 'Test Credential');
    }

    public function test_can_create_credential(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/credentials', [
            'name' => 'New API Token',
            'credential_type' => 'api_token',
            'secret_data' => ['token' => 'sk-new-key-456'],
            'description' => 'A new API token',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'New API Token')
            ->assertJsonPath('data.credential_type', 'api_token');

        $this->assertDatabaseHas('credentials', ['name' => 'New API Token']);
    }

    public function test_create_credential_requires_secret_data(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/credentials', [
            'name' => 'Missing Secret',
            'credential_type' => 'api_token',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['secret_data']);
    }

    public function test_can_update_credential(): void
    {
        $this->actingAsApiUser();
        $credential = $this->createCredential();

        $response = $this->putJson("/api/v1/credentials/{$credential->id}", [
            'name' => 'Updated Credential',
            'description' => 'Updated description',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Credential');
    }

    public function test_can_delete_credential(): void
    {
        $this->actingAsApiUser();
        $credential = $this->createCredential();

        $response = $this->deleteJson("/api/v1/credentials/{$credential->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Credential deleted.']);

        $this->assertSoftDeleted('credentials', ['id' => $credential->id]);
    }

    public function test_can_rotate_credential_secret(): void
    {
        $this->actingAsApiUser();
        $credential = $this->createCredential();

        $response = $this->postJson("/api/v1/credentials/{$credential->id}/rotate", [
            'secret_data' => ['token' => 'sk-rotated-key-789'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $credential->id);
    }

    public function test_unauthenticated_cannot_list_credentials(): void
    {
        $response = $this->getJson('/api/v1/credentials');

        $response->assertStatus(401);
    }
}
