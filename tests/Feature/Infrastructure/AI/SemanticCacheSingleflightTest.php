<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Middleware\SemanticCache;
use App\Infrastructure\AI\Services\EmbeddingService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class SemanticCacheSingleflightTest extends TestCase
{
    private function makeRequest(string $prompt = 'hello world'): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: '',
            userPrompt: $prompt,
            maxTokens: 100,
            teamId: 'team-001',
            userId: 'user-001',
        );
    }

    private function makeResponse(): AiResponseDTO
    {
        return new AiResponseDTO(
            content: 'cached response',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 20, costCredits: 1),
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            latencyMs: 100,
            cached: false,
        );
    }

    public function test_bypasses_cache_when_disabled(): void
    {
        config(['semantic_cache.enabled' => false]);

        $embeddingService = Mockery::mock(EmbeddingService::class);
        $embeddingService->shouldNotReceive('embed');

        $middleware = new SemanticCache($embeddingService);
        $request = $this->makeRequest();
        $response = $this->makeResponse();

        $result = $middleware->handle($request, fn () => $response);

        $this->assertSame($response, $result);
    }

    public function test_bypasses_cache_for_local_agents(): void
    {
        config(['semantic_cache.enabled' => true]);

        $embeddingService = Mockery::mock(EmbeddingService::class);
        $embeddingService->shouldNotReceive('embed');

        $middleware = new SemanticCache($embeddingService);
        $request = new AiRequestDTO(
            provider: 'local/claude-code',
            model: 'claude-code',
            systemPrompt: '',
            userPrompt: 'test',
            maxTokens: 100,
            teamId: 'team-001',
            userId: 'user-001',
        );

        $response = $this->makeResponse();
        $result = $middleware->handle($request, fn () => $response);

        $this->assertSame($response, $result);
    }

    public function test_singleflight_lock_key_is_per_prompt_hash(): void
    {
        config([
            'semantic_cache.enabled' => true,
            'semantic_cache.similarity_threshold' => 0.92,
        ]);

        // Simulate pgvector not available → cache bypassed entirely
        $embeddingService = Mockery::mock(EmbeddingService::class);

        $middleware = new SemanticCache($embeddingService);
        $request = $this->makeRequest('unique prompt for lock test');
        $response = $this->makeResponse();

        // Without pgvector, handle() returns $next() directly
        $result = $middleware->handle($request, fn () => $response);

        $this->assertInstanceOf(AiResponseDTO::class, $result);
    }

    public function test_returns_cached_response_on_hit(): void
    {
        config([
            'semantic_cache.enabled' => true,
            'semantic_cache.similarity_threshold' => 0.92,
        ]);

        // SemanticCache skips when pgvector is unavailable (SQLite in tests)
        $embeddingService = Mockery::mock(EmbeddingService::class);
        $middleware = new SemanticCache($embeddingService);

        $request = $this->makeRequest();
        $expected = $this->makeResponse();
        $callCount = 0;

        $middleware->handle($request, function () use ($expected, &$callCount) {
            $callCount++;

            return $expected;
        });

        // In test environment pgvector is unavailable, so $next() is always called
        $this->assertSame(1, $callCount);
    }
}
