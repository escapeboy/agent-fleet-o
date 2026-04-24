<?php

namespace Tests\Feature\Api\V1;

class BootstrapControllerTest extends ApiTestCase
{
    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/me/bootstrap');

        $response->assertStatus(401);
    }

    public function test_returns_user_team_and_endpoints(): void
    {
        $this->actingAsApiUser();

        $response = $this->getJson('/api/v1/me/bootstrap');

        $response->assertOk()
            ->assertJsonStructure([
                'version',
                'user' => ['id', 'email', 'name'],
                'team' => ['id', 'name', 'role'],
                'endpoints' => ['mcp', 'api'],
                'providers',
                'defaults',
                'capabilities' => ['mcp', 'byok', 'codemode'],
            ]);

        $response->assertJsonPath('user.id', $this->user->id);
        $response->assertJsonPath('user.email', $this->user->email);
        $response->assertJsonPath('team.id', $this->team->id);
        $response->assertJsonPath('team.role', 'owner');
    }

    public function test_providers_payload_is_compact(): void
    {
        $this->actingAsApiUser();

        $response = $this->getJson('/api/v1/me/bootstrap');

        $providers = $response->json('providers');
        $this->assertIsArray($providers);

        foreach ($providers as $key => $provider) {
            $this->assertArrayHasKey('name', $provider, "provider $key missing name");
            $this->assertArrayHasKey('models', $provider, "provider $key missing models");
            $this->assertIsArray($provider['models']);
            // compact payload — model entries must be strings, not full metadata objects
            foreach ($provider['models'] as $model) {
                $this->assertTrue(
                    is_string($model) || is_null($model),
                    "provider $key model entries must be strings; got ".gettype($model),
                );
            }
        }
    }

    public function test_does_not_leak_byok_secrets(): void
    {
        $this->actingAsApiUser();

        $response = $this->getJson('/api/v1/me/bootstrap');

        $body = strtolower($response->getContent() ?: '');
        $this->assertStringNotContainsString('secret_data', $body);
        $this->assertStringNotContainsString('api_key', $body);
        $this->assertStringNotContainsString('bearer_token', $body);
    }

    public function test_defaults_reflect_team_settings(): void
    {
        $this->team->update([
            'settings' => [
                'llm_defaults' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
                'assistant_llm' => ['provider' => 'openai', 'model' => 'gpt-4o'],
            ],
        ]);
        $this->actingAsApiUser();

        $response = $this->getJson('/api/v1/me/bootstrap');

        $response->assertJsonPath('defaults.provider', 'anthropic');
        $response->assertJsonPath('defaults.model', 'claude-sonnet-4-5');
        $response->assertJsonPath('defaults.assistant_provider', 'openai');
        $response->assertJsonPath('defaults.assistant_model', 'gpt-4o');
    }
}
