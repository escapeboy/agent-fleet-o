<?php

namespace Tests\Feature\Domain\Memory;

use App\Domain\Agent\Models\Agent;
use App\Domain\KnowledgeGraph\Models\KgCommunity;
use App\Domain\KnowledgeGraph\Models\KgEdge;
use App\Domain\Memory\Actions\ForgetMemoryAction;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Entity;
use App\Infrastructure\AI\Models\SemanticCacheEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemoryForgetActionTest extends TestCase
{
    use RefreshDatabase;

    private ForgetMemoryAction $action;

    private Team $team;

    private Team $otherTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(ForgetMemoryAction::class);
        $this->team = Team::factory()->create();
        $this->otherTeam = Team::factory()->create();
    }

    public function test_team_erasure_purges_memories_kg_and_cache_and_records_event(): void
    {
        $this->createMemory($this->team);
        $this->createMemory($this->team);
        $entity = $this->createEntity($this->team, 'acme');
        $this->createEdge($this->team, $entity);
        $this->createCommunity($this->team);
        $this->createCacheEntry($this->team->id);

        $counts = $this->action->execute($this->team->id);

        $this->assertSame(2, $counts['memories']);
        $this->assertSame(1, $counts['kg_entities']);
        $this->assertSame(1, $counts['kg_edges']);
        $this->assertSame(1, $counts['kg_communities']);
        $this->assertSame(1, $counts['semantic_cache']);

        $this->assertSame(0, Memory::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
        $this->assertSame(0, Entity::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
        $this->assertSame(0, KgEdge::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
        $this->assertSame(0, KgCommunity::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
        $this->assertSame(0, SemanticCacheEntry::query()->where('team_id', $this->team->id)->count());

        $event = DB::table('deletion_events')->where('team_id', $this->team->id)->first();
        $this->assertNotNull($event);
        $this->assertSame('team', $event->scope);
        $this->assertSame('gdpr_erasure', $event->reason);
        $this->assertSame(2, json_decode($event->purged_counts, true)['memories']);
    }

    public function test_other_teams_data_is_untouched(): void
    {
        $this->createMemory($this->team);
        $this->createMemory($this->otherTeam);
        $otherEntity = $this->createEntity($this->otherTeam, 'other-corp');
        $this->createEdge($this->otherTeam, $otherEntity);
        $this->createCommunity($this->otherTeam);
        $this->createCacheEntry($this->otherTeam->id);

        $this->action->execute($this->team->id);

        $this->assertSame(1, Memory::withoutGlobalScopes()->where('team_id', $this->otherTeam->id)->count());
        $this->assertSame(1, Entity::withoutGlobalScopes()->where('team_id', $this->otherTeam->id)->count());
        $this->assertSame(1, KgEdge::withoutGlobalScopes()->where('team_id', $this->otherTeam->id)->count());
        $this->assertSame(1, KgCommunity::withoutGlobalScopes()->where('team_id', $this->otherTeam->id)->count());
        $this->assertSame(1, SemanticCacheEntry::query()->where('team_id', $this->otherTeam->id)->count());
    }

    public function test_agent_scoped_erasure_only_removes_that_agents_memories(): void
    {
        $agentA = Agent::factory()->create(['team_id' => $this->team->id]);
        $agentB = Agent::factory()->create(['team_id' => $this->team->id]);

        $this->createMemory($this->team, $agentA);
        $this->createMemory($this->team, $agentB);
        $entity = $this->createEntity($this->team, 'acme');
        $this->createCommunity($this->team);

        $counts = $this->action->execute($this->team->id, agentId: $agentA->id);

        $this->assertSame(1, $counts['memories']);
        $this->assertSame(0, $counts['kg_entities']);
        $this->assertSame(0, $counts['kg_communities']);

        $this->assertSame(0, Memory::withoutGlobalScopes()->where('agent_id', $agentA->id)->count());
        $this->assertSame(1, Memory::withoutGlobalScopes()->where('agent_id', $agentB->id)->count());

        // KG / cache are team-wide and untouched by an agent-scoped erasure.
        $this->assertSame(1, Entity::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
        $this->assertSame(1, KgCommunity::withoutGlobalScopes()->where('team_id', $this->team->id)->count());

        $event = DB::table('deletion_events')->where('team_id', $this->team->id)->first();
        $this->assertSame('agent', $event->scope);
        $this->assertSame($agentA->id, $event->agent_id);
    }

    private function createMemory(Team $team, ?Agent $agent = null): Memory
    {
        return Memory::create([
            'team_id' => $team->id,
            'agent_id' => $agent?->id,
            'content' => 'a memory',
            'source_type' => 'experiment',
            'confidence' => 0.9,
            'importance' => 0.5,
            'metadata' => [],
        ]);
    }

    private function createEntity(Team $team, string $name): Entity
    {
        return Entity::create([
            'team_id' => $team->id,
            'type' => 'company',
            'name' => $name,
            'canonical_name' => $name,
            'metadata' => [],
            'mention_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    private function createEdge(Team $team, Entity $entity): KgEdge
    {
        return KgEdge::create([
            'team_id' => $team->id,
            'source_entity_id' => $entity->id,
            'target_entity_id' => $entity->id,
            'relation_type' => 'relates_to',
            'fact' => 'self relation',
            'attributes' => [],
        ]);
    }

    private function createCommunity(Team $team): KgCommunity
    {
        return KgCommunity::create([
            'team_id' => $team->id,
            'label' => 'cluster',
            'summary' => 'a cluster',
            'entity_ids' => [],
            'size' => 0,
            'top_entities' => [],
        ]);
    }

    private function createCacheEntry(string $teamId): SemanticCacheEntry
    {
        return SemanticCacheEntry::create([
            'team_id' => $teamId,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'prompt_hash' => substr(md5($teamId), 0, 32),
            'request_text' => 'hello',
            'response_content' => 'world',
            'response_metadata' => [],
            'hit_count' => 0,
        ]);
    }
}
