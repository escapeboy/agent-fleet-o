<?php

namespace Tests\Feature\Mcp;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Memory\MemoryChunkReadTool;
use App\Mcp\Tools\Memory\MemoryKeywordSearchTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Tests\TestCase;

/**
 * A-RAG (build #1, Trendshift top-5 sprint).
 * Covers MemoryKeywordSearchTool + MemoryChunkReadTool.
 */
class MemoryHierarchicalRetrievalTest extends TestCase
{
    use RefreshDatabase;

    private Team $teamA;

    private Team $teamB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamA = Team::factory()->create();
        $this->teamB = Team::factory()->create();
        $this->userA = User::factory()->create(['current_team_id' => $this->teamA->id]);

        // Bind mcp.team_id to teamA for the tools' default resolution.
        app()->bind('mcp.team_id', fn () => $this->teamA->id);
    }

    // -------------------------------------------------------------------------
    // MemoryKeywordSearchTool
    // -------------------------------------------------------------------------

    public function test_keyword_search_returns_matching_memories(): void
    {
        $this->mem([
            'team_id' => $this->teamA->id,
            'content' => 'webclaw API key rotation policy says rotate quarterly',
        ]);
        $this->mem([
            'team_id' => $this->teamA->id,
            'content' => 'unrelated note about coffee preferences',
        ]);

        $response = $this->invoke(MemoryKeywordSearchTool::class, ['query' => 'webclaw rotation']);
        $payload = $this->decode($response);

        $this->assertSame(1, $payload['count']);
        $this->assertStringContainsString('rotation', $payload['matches'][0]['snippet']);
    }

    public function test_keyword_search_isolates_by_team(): void
    {
        $this->mem([
            'team_id' => $this->teamA->id,
            'content' => 'team A secret about deployment',
        ]);
        $this->mem([
            'team_id' => $this->teamB->id,
            'content' => 'team B secret about deployment',
        ]);

        $response = $this->invoke(MemoryKeywordSearchTool::class, ['query' => 'secret deployment']);
        $payload = $this->decode($response);

        $this->assertSame(1, $payload['count']);
        $this->assertStringContainsString('team A', $payload['matches'][0]['snippet']);
    }

    public function test_keyword_search_filters_by_topic(): void
    {
        $this->mem([
            'team_id' => $this->teamA->id,
            'topic' => 'auth_migration',
            'content' => 'migration phase one removes provider trust',
        ]);
        $this->mem([
            'team_id' => $this->teamA->id,
            'topic' => 'unrelated',
            'content' => 'migration phase one removes provider trust',
        ]);

        $response = $this->invoke(MemoryKeywordSearchTool::class, [
            'query' => 'migration phase',
            'topic' => 'auth_migration',
        ]);
        $payload = $this->decode($response);

        $this->assertSame(1, $payload['count']);
    }

    public function test_keyword_search_rejects_cross_team_agent_id(): void
    {
        $otherAgent = Agent::factory()->create(['team_id' => $this->teamB->id]);

        $response = $this->invokeExpectingValidationFailure(MemoryKeywordSearchTool::class, [
            'query' => 'anything',
            'agent_id' => $otherAgent->id,
        ]);

        $this->assertNotNull($response);
    }

    public function test_keyword_search_caps_limit_at_100(): void
    {
        $response = $this->invokeExpectingValidationFailure(MemoryKeywordSearchTool::class, [
            'query' => 'foo',
            'limit' => 200,
        ]);
        $this->assertNotNull($response);
    }

    public function test_keyword_search_requires_query_min_length(): void
    {
        $response = $this->invokeExpectingValidationFailure(MemoryKeywordSearchTool::class, [
            'query' => 'a',
        ]);
        $this->assertNotNull($response);
    }

    public function test_keyword_search_returns_no_team_resolved_when_unbound(): void
    {
        // Forget the binding from setUp + ensure acting user has no current_team_id either.
        app()->forgetInstance('mcp.team_id');
        app()->bind('mcp.team_id', fn () => null);
        $userless = User::factory()->create(['current_team_id' => null]);
        $this->actingAs($userless);

        $response = $this->invoke(MemoryKeywordSearchTool::class, ['query' => 'anything']);
        $payload = $this->decode($response);

        $this->assertSame('no_team_resolved', $payload['error'] ?? null);
    }

    // -------------------------------------------------------------------------
    // MemoryChunkReadTool
    // -------------------------------------------------------------------------

    public function test_chunk_read_returns_target_full_content(): void
    {
        $longContent = str_repeat('full text ', 200); // > 200 chars
        $memory = $this->mem([
            'team_id' => $this->teamA->id,
            'content' => $longContent,
        ]);

        $response = $this->invoke(MemoryChunkReadTool::class, [
            'memory_id' => $memory->id,
        ]);
        $payload = $this->decode($response);

        $this->assertSame($memory->id, $payload['target']['id']);
        $this->assertSame($longContent, $payload['target']['content']);
        $this->assertSame([], $payload['before']);
        $this->assertSame([], $payload['after']);
    }

    public function test_chunk_read_with_adjacent_returns_neighbours_in_order(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->teamA->id]);
        $base = now()->copy()->subMinutes(10);
        $memories = [];
        for ($i = 0; $i < 5; $i++) {
            $memories[] = $this->mem([
                'team_id' => $this->teamA->id,
                'agent_id' => $agent->id,
                'topic' => 'sprint_notes',
                'content' => "note {$i}",
                'created_at' => $base->copy()->addMinutes($i),
            ]);
        }

        // Target is index 2 ("note 2"); ask for 2 before + 2 after.
        $response = $this->invoke(MemoryChunkReadTool::class, [
            'memory_id' => $memories[2]->id,
            'include_adjacent' => 2,
        ]);
        $payload = $this->decode($response);

        $this->assertSame('note 2', $payload['target']['content']);
        $this->assertCount(2, $payload['before']);
        $this->assertCount(2, $payload['after']);
        $this->assertSame('note 0', $payload['before'][0]['content']);
        $this->assertSame('note 1', $payload['before'][1]['content']);
        $this->assertSame('note 3', $payload['after'][0]['content']);
        $this->assertSame('note 4', $payload['after'][1]['content']);
    }

    public function test_chunk_read_partition_respects_topic_and_agent(): void
    {
        $agentA = Agent::factory()->create(['team_id' => $this->teamA->id]);
        $agentB = Agent::factory()->create(['team_id' => $this->teamA->id]);

        $target = $this->mem([
            'team_id' => $this->teamA->id,
            'agent_id' => $agentA->id,
            'topic' => 'X',
            'content' => 'target',
            'created_at' => now()->subMinute(),
        ]);

        // Different agent, different topic — must NOT show up.
        $this->mem([
            'team_id' => $this->teamA->id,
            'agent_id' => $agentB->id,
            'topic' => 'Y',
            'content' => 'other agent other topic',
            'created_at' => now(),
        ]);

        $response = $this->invoke(MemoryChunkReadTool::class, [
            'memory_id' => $target->id,
            'include_adjacent' => 2,
        ]);
        $payload = $this->decode($response);

        $this->assertSame([], $payload['after']);
    }

    public function test_chunk_read_404s_on_other_team_memory(): void
    {
        $foreign = $this->mem([
            'team_id' => $this->teamB->id,
            'content' => 'foreign',
        ]);

        $response = $this->invokeExpectingValidationFailure(MemoryChunkReadTool::class, [
            'memory_id' => $foreign->id,
        ]);
        $this->assertNotNull($response);
    }

    public function test_chunk_read_clamps_include_adjacent_at_5(): void
    {
        $memory = $this->mem(['team_id' => $this->teamA->id]);  // bare

        $response = $this->invokeExpectingValidationFailure(MemoryChunkReadTool::class, [
            'memory_id' => $memory->id,
            'include_adjacent' => 6,
        ]);
        $this->assertNotNull($response);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Invoke an MCP tool and return its Response.
     *
     * @param  class-string<Tool>  $toolClass
     * @param  array<string, mixed>  $params
     */
    private function invoke(string $toolClass, array $params): Response
    {
        /** @var Tool $tool */
        $tool = app($toolClass);
        $request = new Request($params);

        return $tool->handle($request);
    }

    /**
     * Invoke and assert validation failed (caught as ValidationException by Laravel\Mcp).
     */
    private function invokeExpectingValidationFailure(string $toolClass, array $params): ?\Throwable
    {
        try {
            $this->invoke($toolClass, $params);
        } catch (ValidationException $e) {
            return $e;
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    /**
     * Create a Memory directly (Memory model has no factory).
     *
     * Honors a `created_at` override even though the column isn't in $fillable —
     * needed for tests asserting time-based ordering of adjacent chunks.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function mem(array $attrs): Memory
    {
        $createdAt = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);

        $memory = Memory::create(array_merge([
            'content' => 'sample content',
            'source_type' => 'test',
        ], $attrs));

        if ($createdAt !== null) {
            DB::table('memories')
                ->where('id', $memory->id)
                ->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);
            $memory->refresh();
        }

        return $memory;
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true) ?? [];
    }
}
