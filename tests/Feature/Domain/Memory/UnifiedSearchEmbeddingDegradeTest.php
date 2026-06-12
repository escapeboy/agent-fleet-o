<?php

namespace Tests\Feature\Domain\Memory;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Actions\UnifiedMemorySearchAction;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\EmbeddingProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Regression: with no usable embedding key (BYOK install, empty platform
 * key) unified search must degrade to the remaining lanes, not throw.
 * Observed on prod 2026-06-12: OpenAI 401 from the unguarded KG-lane query
 * embedding killed every unified search call.
 */
class UnifiedSearchEmbeddingDegradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_survives_embedding_provider_failure(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create();

        $this->app->instance(EmbeddingProviderInterface::class, new ThrowingEmbeddingProvider);

        $result = app(UnifiedMemorySearchAction::class)->execute(
            teamId: $team->id,
            query: 'anything at all',
            agentId: $agent->id,
        );

        $this->assertInstanceOf(Collection::class, $result);
    }

    public function test_search_survives_null_team_embedding(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create();

        $this->app->instance(EmbeddingProviderInterface::class, new NullEmbeddingProvider);

        $result = app(UnifiedMemorySearchAction::class)->execute(
            teamId: $team->id,
            query: 'anything at all',
            agentId: $agent->id,
        );

        $this->assertInstanceOf(Collection::class, $result);
    }
}

class ThrowingEmbeddingProvider implements EmbeddingProviderInterface
{
    public function embed(string $text): array
    {
        throw new \RuntimeException('OpenAI Error [401]: no API key');
    }

    public function embedForTeam(string $text, ?string $teamId): ?array
    {
        throw new \RuntimeException('OpenAI Error [401]: no API key');
    }

    public function formatForPgvector(array $embedding): string
    {
        return '['.implode(',', $embedding).']';
    }

    public function dimensions(): int
    {
        return 8;
    }

    public function identifier(): string
    {
        return 'throwing';
    }
}

class NullEmbeddingProvider implements EmbeddingProviderInterface
{
    public function embed(string $text): array
    {
        throw new \RuntimeException('platform key missing');
    }

    public function embedForTeam(string $text, ?string $teamId): ?array
    {
        return null;
    }

    public function formatForPgvector(array $embedding): string
    {
        return '['.implode(',', $embedding).']';
    }

    public function dimensions(): int
    {
        return 8;
    }

    public function identifier(): string
    {
        return 'null-team';
    }
}
