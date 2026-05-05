<?php

namespace Tests\Unit\Telemetry;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\Telemetry\TenantTracerProviderFactory;
use App\Infrastructure\Telemetry\TracerProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use ReflectionClass;
use Tests\TestCase;

class TenantTracerProviderFactoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeam(array $observability = []): Team
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'T',
            'slug' => 't-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => $observability ? ['observability' => $observability] : [],
        ]);
        $user->update(['current_team_id' => $team->id]);

        return $team;
    }

    private function peekOverrides(TracerProvider $provider): ?array
    {
        $r = new ReflectionClass($provider);
        $p = $r->getProperty('overrides');
        $p->setAccessible(true);

        return $p->getValue($provider);
    }

    public function test_returns_platform_default_when_team_id_null(): void
    {
        $factory = app(TenantTracerProviderFactory::class);
        $result = $factory->forTeam(null);
        $this->assertInstanceOf(TracerProvider::class, $result);
        $this->assertNull($this->peekOverrides($result));
    }

    public function test_returns_default_when_team_has_no_observability(): void
    {
        $team = $this->makeTeam();
        $factory = app(TenantTracerProviderFactory::class);
        $result = $factory->forTeam($team->id);
        $this->assertNull($this->peekOverrides($result));
    }

    public function test_returns_default_when_observability_disabled(): void
    {
        $team = $this->makeTeam([
            'enabled' => false,
            'endpoint' => 'https://logfire-api.pydantic.dev',
        ]);
        $factory = app(TenantTracerProviderFactory::class);
        $result = $factory->forTeam($team->id);
        $this->assertNull($this->peekOverrides($result));
    }

    public function test_returns_default_when_endpoint_empty(): void
    {
        $team = $this->makeTeam([
            'enabled' => true,
            'endpoint' => '',
        ]);
        $factory = app(TenantTracerProviderFactory::class);
        $result = $factory->forTeam($team->id);
        $this->assertNull($this->peekOverrides($result));
    }

    public function test_returns_overriding_provider_when_enabled(): void
    {
        $team = $this->makeTeam([
            'enabled' => true,
            'endpoint' => 'https://logfire-api.pydantic.dev',
            'sample_rate' => 0.5,
            'service_name' => 'team-alpha',
            'otlp_token_encrypted' => Crypt::encryptString('token-xyz'),
        ]);

        $factory = app(TenantTracerProviderFactory::class);
        $result = $factory->forTeam($team->id);

        $overrides = $this->peekOverrides($result);
        $this->assertIsArray($overrides);
        $this->assertTrue($overrides['enabled']);
        $this->assertSame('https://logfire-api.pydantic.dev', $overrides['endpoint']);
        $this->assertSame(0.5, $overrides['sample_rate']);
        $this->assertSame('team-alpha', $overrides['service_name']);
        $this->assertSame(['Authorization' => 'Bearer token-xyz'], $overrides['headers']);
    }

    public function test_bare_token_gets_bearer_prefix(): void
    {
        $team = $this->makeTeam([
            'enabled' => true,
            'endpoint' => 'https://honeycomb.io',
            'otlp_token_encrypted' => Crypt::encryptString('honey-abc'),
        ]);

        $factory = app(TenantTracerProviderFactory::class);
        $result = $factory->forTeam($team->id);
        $overrides = $this->peekOverrides($result);
        $this->assertSame('Bearer honey-abc', $overrides['headers']['Authorization']);
    }

    public function test_already_prefixed_auth_is_not_double_prefixed(): void
    {
        $team = $this->makeTeam([
            'enabled' => true,
            'endpoint' => 'https://honeycomb.io',
            'otlp_token_encrypted' => Crypt::encryptString('Basic user:pass'),
        ]);

        $factory = app(TenantTracerProviderFactory::class);
        $result = $factory->forTeam($team->id);
        $overrides = $this->peekOverrides($result);
        $this->assertSame('Basic user:pass', $overrides['headers']['Authorization']);
    }

    public function test_malformed_token_drops_auth_header(): void
    {
        $team = $this->makeTeam([
            'enabled' => true,
            'endpoint' => 'https://honeycomb.io',
            'otlp_token_encrypted' => 'not-a-valid-ciphertext',
        ]);

        $factory = app(TenantTracerProviderFactory::class);
        $result = $factory->forTeam($team->id);
        $overrides = $this->peekOverrides($result);
        $this->assertArrayNotHasKey('Authorization', $overrides['headers'] ?? []);
    }

    public function test_cache_returns_same_instance_on_repeat_lookup(): void
    {
        $team = $this->makeTeam([
            'enabled' => true,
            'endpoint' => 'https://honeycomb.io',
        ]);

        $factory = app(TenantTracerProviderFactory::class);
        $a = $factory->forTeam($team->id);
        $b = $factory->forTeam($team->id);
        $this->assertSame($a, $b);
    }

    public function test_forget_invalidates_cache(): void
    {
        $team = $this->makeTeam([
            'enabled' => true,
            'endpoint' => 'https://a.example',
        ]);

        $factory = app(TenantTracerProviderFactory::class);
        $first = $factory->forTeam($team->id);

        $team->update(['settings' => ['observability' => [
            'enabled' => true,
            'endpoint' => 'https://b.example',
        ]]]);
        $factory->forget($team->id);

        $second = $factory->forTeam($team->id);
        $this->assertNotSame($first, $second);
        $this->assertSame('https://b.example', $this->peekOverrides($second)['endpoint']);
    }
}
