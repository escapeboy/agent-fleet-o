<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Infrastructure\AI\Contracts\EmbeddingProviderInterface;
use App\Infrastructure\AI\Exceptions\LocalEmbeddingNotConfiguredException;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Models\SemanticCacheEntry;
use App\Infrastructure\AI\Services\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmbeddingProviderSeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_cloud_driver_resolves_to_embedding_service(): void
    {
        config(['memory.embedding_driver' => 'cloud']);

        $provider = app(EmbeddingProviderInterface::class);

        $this->assertInstanceOf(EmbeddingService::class, $provider);
    }

    public function test_local_driver_throws_until_a_provider_is_bound(): void
    {
        config(['memory.embedding_driver' => 'local']);

        $this->expectException(LocalEmbeddingNotConfiguredException::class);

        app(EmbeddingProviderInterface::class);
    }

    public function test_unknown_driver_throws_invalid_argument(): void
    {
        config(['memory.embedding_driver' => 'banana']);

        $this->expectException(\InvalidArgumentException::class);

        app(EmbeddingProviderInterface::class);
    }

    public function test_identifier_namespaces_by_provider_and_model(): void
    {
        $service = new EmbeddingService('openai', 'text-embedding-3-small');

        $this->assertSame('openai:text-embedding-3-small', $service->identifier());
    }

    public function test_dimensions_mirror_configured_vector_size(): void
    {
        config(['memory.embedding_dimensions' => 1536]);

        $this->assertSame(1536, (new EmbeddingService)->dimensions());
    }

    public function test_embedding_service_satisfies_the_seam_contract(): void
    {
        $this->assertInstanceOf(EmbeddingProviderInterface::class, new EmbeddingService);
    }

    public function test_cache_entries_are_isolated_by_embedding_model(): void
    {
        // Same team/provider/model/prompt — only the embedding_model differs.
        // The namespace filter SemanticCache applies must keep these disjoint so
        // vectors from different embedding backends are never compared.
        // A real team is required: semantic_cache_entries.team_id now carries a
        // cascadeOnDelete FK to teams (GDPR erasure cascade), so a synthetic id
        // would violate it on insert.
        $team = Team::factory()->create();
        $shared = [
            'team_id' => $team->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'prompt_hash' => str_repeat('a', 32),
            'request_text' => 'hi',
            'response_content' => 'cached',
            'hit_count' => 0,
        ];

        SemanticCacheEntry::create($shared + ['embedding_model' => 'text-embedding-3-small']);
        SemanticCacheEntry::create($shared + ['embedding_model' => 'multilingual-e5-base']);

        $openai = SemanticCacheEntry::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('provider', 'anthropic')
            ->where('model', 'claude-sonnet-4-5')
            ->where('embedding_model', 'text-embedding-3-small')
            ->get();

        $this->assertCount(1, $openai);
        $this->assertSame('text-embedding-3-small', $openai->first()->embedding_model);
    }

    public function test_migration_added_the_embedding_model_column(): void
    {
        $this->assertTrue(Schema::hasColumn('semantic_cache_entries', 'embedding_model'));
    }
}
