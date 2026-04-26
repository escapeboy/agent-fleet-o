<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Infrastructure\AI\Services\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies EmbeddingService::embedForTeam degrades gracefully when no usable
 * key is reachable. Production logs were filling up with OpenAI 401 warnings
 * from teams running on BYOK without a platform-level OPENAI_API_KEY env var.
 */
class EmbeddingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure no platform key bleeds through from .env into tests
        config(['services.platform_api_keys.openai' => null]);
        config(['prism.providers.openai.api_key' => null]);
    }

    public function test_returns_null_when_no_team_credential_and_no_platform_key(): void
    {
        $team = Team::factory()->create();
        $service = new EmbeddingService('openai', 'text-embedding-3-small');

        $this->assertNull($service->embedForTeam('hello', $team->id));
    }

    public function test_returns_null_when_team_id_omitted_and_no_platform_key(): void
    {
        $service = new EmbeddingService('openai', 'text-embedding-3-small');

        $this->assertNull($service->embedForTeam('hello', null));
    }

    public function test_does_not_throw_for_unknown_provider_without_key(): void
    {
        $service = new EmbeddingService('voyage', 'voyage-3');

        // Unknown providers fall through to safeEmbed which catches errors
        // and returns null. The point is we never let a 401 escape callers.
        $this->assertNull($service->embedForTeam('hello', null));
    }

    public function test_format_for_pgvector_renders_bracketed_csv(): void
    {
        $service = new EmbeddingService;

        $this->assertSame('[0.1,0.2,0.3]', $service->formatForPgvector([0.1, 0.2, 0.3]));
    }

    public function test_skips_inactive_team_credential(): void
    {
        $team = Team::factory()->create();
        TeamProviderCredential::create([
            'team_id' => $team->id,
            'provider' => 'openai',
            'is_active' => false,
            'credentials' => ['api_key' => 'sk-disabled'],
        ]);

        $service = new EmbeddingService('openai', 'text-embedding-3-small');

        // Inactive credential should NOT be applied; with no platform key
        // either, embedForTeam degrades to null.
        $this->assertNull($service->embedForTeam('hello', $team->id));
    }
}
