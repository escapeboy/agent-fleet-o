<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Middleware\SemanticCache;
use App\Infrastructure\AI\Models\SemanticCacheEntry;
use App\Infrastructure\AI\Services\EmbeddingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Prism\Prism\Facades\Tool as PrismTool;
use Tests\TestCase;

/**
 * The semantic cache keys on prompt text only. Serving a tool-using request from
 * it returns the stored final text with zero tool calls, so a run that should
 * pay invoices reports "done" without paying anything.
 */
class SemanticCacheToolRequestTest extends TestCase
{
    use RefreshDatabase;

    private int $embedCalls = 0;

    private string $teamId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['semantic_cache.enabled' => true]);

        $user = User::factory()->create();
        $this->teamId = Team::create([
            'name' => 'Cache Team',
            'slug' => 'cache-'.Str::lower(Str::random(6)),
            'owner_id' => $user->id,
            'settings' => [],
        ])->id;
    }

    private function middleware(): SemanticCache
    {
        $embeddings = Mockery::mock(EmbeddingService::class);
        $embeddings->shouldReceive('identifier')->andReturn('test:embedding');
        $embeddings->shouldReceive('embed')->andReturnUsing(function () {
            $this->embedCalls++;

            return array_fill(0, 3, 0.1);
        });
        $embeddings->shouldReceive('formatForPgvector')->andReturn('[0.1,0.1,0.1]');

        // SQLite has no pgvector; pretend it does so the cache actually runs.
        return new class($embeddings) extends SemanticCache
        {
            protected function hasVectorExtension(): bool
            {
                return true;
            }
        };
    }

    private function request(bool $withTools): AiRequestDTO
    {
        $tools = $withTools
            ? [PrismTool::as('pay_invoice')->for('Pay one invoice')->withStringParameter('id', 'Invoice id')->using(fn (string $id) => 'paid')]
            : [];

        return new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'You are a bookkeeper.',
            userPrompt: 'Pay all open invoices.',
            maxTokens: 100,
            teamId: $this->teamId,
            tools: $tools,
        );
    }

    private function seedExactEntry(AiRequestDTO $request): SemanticCacheEntry
    {
        return SemanticCacheEntry::withoutGlobalScopes()->create([
            'team_id' => $request->teamId,
            'provider' => $request->provider,
            'model' => $request->model,
            'embedding_model' => 'test:embedding',
            'prompt_hash' => hash('xxh128', trim($request->systemPrompt.' '.$request->userPrompt)),
            'request_text' => 'old',
            'response_content' => 'All invoices paid.',
            'response_metadata' => [],
            'expires_at' => now()->addDay(),
        ]);
    }

    private function liveResponse(): AiResponseDTO
    {
        return new AiResponseDTO(
            content: 'live answer',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 20, costCredits: 1),
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            latencyMs: 100,
            toolCallsCount: 3,
        );
    }

    public function test_tool_request_is_not_served_from_an_exact_cache_hit(): void
    {
        $request = $this->request(withTools: true);
        $entry = $this->seedExactEntry($request);
        $live = $this->liveResponse();
        $nextCalls = 0;

        $result = $this->middleware()->handle($request, function () use ($live, &$nextCalls) {
            $nextCalls++;

            return $live;
        });

        $this->assertSame(1, $nextCalls, 'a tool request must reach the model');
        $this->assertSame($live, $result);
        $this->assertFalse($result->cached);
        $this->assertSame(0, (int) $entry->fresh()->hit_count);
    }

    public function test_tool_request_does_not_embed_or_store(): void
    {
        $this->middleware()->handle($this->request(withTools: true), fn () => $this->liveResponse());

        $this->assertSame(0, $this->embedCalls);
        $this->assertSame(0, SemanticCacheEntry::withoutGlobalScopes()->count());
    }

    public function test_request_without_tools_still_hits_the_cache(): void
    {
        $request = $this->request(withTools: false);
        $this->seedExactEntry($request);

        $result = $this->middleware()->handle($request, fn () => $this->fail('cache hit expected'));

        $this->assertTrue($result->cached);
        $this->assertSame('All invoices paid.', $result->content);
    }
}
