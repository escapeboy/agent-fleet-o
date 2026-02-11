<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthControllerTest extends ApiTestCase
{
    public function test_can_issue_token_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret123'),
            'current_team_id' => $this->team->id,
        ]);
        $this->team->users()->attach($user, ['role' => 'member']);

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'secret123',
            'device_name' => 'phpunit',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'token',
                'expires_at',
                'user' => ['id', 'name', 'email', 'current_team'],
            ]);
    }

    public function test_token_rejected_with_wrong_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret123'),
            'current_team_id' => $this->team->id,
        ]);

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_can_refresh_token(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/auth/refresh');

        $response->assertOk()
            ->assertJsonStructure(['token', 'expires_at']);
    }

    public function test_can_logout(): void
    {
        $this->actingAsApiUser();

        $response = $this->deleteJson('/api/v1/auth/token');

        $response->assertOk()
            ->assertJson(['message' => 'Token revoked.']);
    }

    public function test_can_list_devices(): void
    {
        $this->actingAsApiUser();

        $response = $this->getJson('/api/v1/auth/devices');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_can_get_current_user(): void
    {
        $this->actingAsApiUser();

        $response = $this->getJson('/api/v1/me');

        $response->assertOk()
            ->assertJsonStructure(['data' => ['id', 'name', 'email']]);
    }

    public function test_can_update_profile(): void
    {
        $this->actingAsApiUser();

        $response = $this->putJson('/api/v1/me', [
            'name' => 'Updated Name',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401);
    }
}
