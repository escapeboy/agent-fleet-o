<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CustomEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_endpoint_requires_team_id_and_provider_name(): void
    {
        $gateway = app(PrismAiGateway::class);

        $request = new AiRequestDTO(
            provider: 'custom_endpoint',
            model: 'gpt-4o',
            systemPrompt: 'Test',
            userPrompt: 'Hello',
            teamId: null,
            providerName: null,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Custom endpoint requires both teamId and providerName');

        $gateway->complete($request);
    }

    public function test_custom_endpoint_throws_when_credential_not_found(): void
    {
        $team = Team::factory()->create();
        $gateway = app(PrismAiGateway::class);

        $request = new AiRequestDTO(
            provider: 'custom_endpoint',
            model: 'gpt-4o',
            systemPrompt: 'Test',
            userPrompt: 'Hello',
            teamId: $team->id,
            providerName: 'nonexistent-proxy',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Custom endpoint 'nonexistent-proxy' not found or inactive");

        $gateway->complete($request);
    }

    public function test_custom_endpoint_skips_inactive_credentials(): void
    {
        $team = Team::factory()->create();

        TeamProviderCredential::create([
            'team_id' => $team->id,
            'provider' => 'custom_endpoint',
            'name' => 'my-proxy',
            'credentials' => [
                'base_url' => 'https://proxy.example.com',
                'api_key' => 'sk-test',
                'models' => ['gpt-4o'],
            ],
            'is_active' => false,
        ]);

        $gateway = app(PrismAiGateway::class);

        $request = new AiRequestDTO(
            provider: 'custom_endpoint',
            model: 'gpt-4o',
            systemPrompt: 'Test',
            userPrompt: 'Hello',
            teamId: $team->id,
            providerName: 'my-proxy',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Custom endpoint 'my-proxy' not found or inactive");

        $gateway->complete($request);
    }

    public function test_cost_calculator_returns_zero_for_custom_endpoint(): void
    {
        $gateway = app(PrismAiGateway::class);

        $request = new AiRequestDTO(
            provider: 'custom_endpoint',
            model: 'any-model',
            systemPrompt: 'Test',
            userPrompt: 'Hello',
        );

        $cost = $gateway->estimateCost($request);

        $this->assertSame(0, $cost);
    }

    public function test_provider_resolver_returns_custom_endpoints_for_team(): void
    {
        $team = Team::factory()->create();

        TeamProviderCredential::create([
            'team_id' => $team->id,
            'provider' => 'custom_endpoint',
            'name' => 'proxy-a',
            'credentials' => [
                'base_url' => 'https://a.example.com',
                'api_key' => '',
                'models' => ['gpt-4o'],
            ],
            'is_active' => true,
        ]);

        TeamProviderCredential::create([
            'team_id' => $team->id,
            'provider' => 'custom_endpoint',
            'name' => 'proxy-b',
            'credentials' => [
                'base_url' => 'https://b.example.com',
                'api_key' => 'sk-b',
                'models' => ['claude-3-opus'],
            ],
            'is_active' => true,
        ]);

        $resolver = app(ProviderResolver::class);
        $endpoints = $resolver->customEndpointsForTeam($team);

        $this->assertCount(2, $endpoints);
        $this->assertContains('proxy-a', $endpoints->pluck('name')->toArray());
        $this->assertContains('proxy-b', $endpoints->pluck('name')->toArray());
    }

    public function test_provider_resolver_returns_empty_for_null_team(): void
    {
        $resolver = app(ProviderResolver::class);
        $endpoints = $resolver->customEndpointsForTeam(null);

        $this->assertTrue($endpoints->isEmpty());
    }

    public function test_multiple_custom_endpoints_can_coexist_for_same_team(): void
    {
        $team = Team::factory()->create();

        $ep1 = TeamProviderCredential::create([
            'team_id' => $team->id,
            'provider' => 'custom_endpoint',
            'name' => 'endpoint-1',
            'credentials' => ['base_url' => 'https://one.example.com', 'api_key' => ''],
            'is_active' => true,
        ]);

        $ep2 = TeamProviderCredential::create([
            'team_id' => $team->id,
            'provider' => 'custom_endpoint',
            'name' => 'endpoint-2',
            'credentials' => ['base_url' => 'https://two.example.com', 'api_key' => ''],
            'is_active' => true,
        ]);

        $this->assertDatabaseCount('team_provider_credentials', 2);
        $this->assertNotEquals($ep1->id, $ep2->id);
    }
}
