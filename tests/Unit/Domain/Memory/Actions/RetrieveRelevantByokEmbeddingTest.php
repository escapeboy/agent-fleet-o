<?php

namespace Tests\Unit\Domain\Memory\Actions;

use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Infrastructure\AI\Contracts\EmbeddingProviderInterface;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The query-side embedding must route through embedForTeam (team BYOK key),
 * not embed() (platform key) — the platform key is empty on BYOK installs, so
 * embed() 401s and previously killed the whole retrieval (Recall collapsed to
 * keyword-only, found via memory:benchmark-retrieval on prod 2026-06-12).
 * generateEmbedding must return null (not throw) when no key is available so
 * execute() can fall back to recency/importance scoring.
 */
class RetrieveRelevantByokEmbeddingTest extends TestCase
{
    private function callGenerateEmbedding(EmbeddingProviderInterface $provider, ?string $teamId): ?string
    {
        $this->app->instance(EmbeddingProviderInterface::class, $provider);

        $method = new ReflectionMethod(RetrieveRelevantMemoriesAction::class, 'generateEmbedding');
        $method->setAccessible(true);

        return $method->invoke(new RetrieveRelevantMemoriesAction, 'find the deploy runbook', $teamId);
    }

    public function test_uses_embed_for_team_not_platform_embed(): void
    {
        $provider = new class implements EmbeddingProviderInterface
        {
            public ?string $sawTeamId = 'unset';

            public function embed(string $text): array
            {
                throw new \RuntimeException('platform embed() must not be called on a BYOK retrieval');
            }

            public function embedForTeam(string $text, ?string $teamId): ?array
            {
                $this->sawTeamId = $teamId;

                return array_fill(0, 4, 0.1);
            }

            public function formatForPgvector(array $embedding): string
            {
                return '['.implode(',', $embedding).']';
            }

            public function dimensions(): int
            {
                return 4;
            }

            public function identifier(): string
            {
                return 'spy';
            }
        };

        $result = $this->callGenerateEmbedding($provider, 'team-123');

        $this->assertSame('team-123', $provider->sawTeamId);
        $this->assertSame('[0.1,0.1,0.1,0.1]', $result);
    }

    public function test_returns_null_when_no_key_available(): void
    {
        $provider = new class implements EmbeddingProviderInterface
        {
            public function embed(string $text): array
            {
                throw new \RuntimeException('no key');
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
                return 4;
            }

            public function identifier(): string
            {
                return 'no-key';
            }
        };

        $this->assertNull($this->callGenerateEmbedding($provider, 'team-123'));
    }
}
