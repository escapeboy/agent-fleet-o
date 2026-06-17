<?php

namespace Tests\Feature\Domain\Memory;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Domain\Memory\Actions\UnifiedMemorySearchAction;
use App\Domain\Memory\Enums\MemoryCategory;
use App\Domain\Memory\Enums\MemoryPreferenceSubtype;
use App\Domain\Memory\Enums\MemoryRelevance;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Models\Memory;
use App\Domain\Memory\Services\MemoryContextInjector;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\EmbeddingProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Oracle "RAG → memory" borrows: two-path retrieval (Path A preference
 * enumeration), relevance-tier labels, provisional exclusion, and
 * degraded-mode telemetry.
 */
class MemoryStatefulBorrowsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);
    }

    // ---- Path A: preference enumeration is exhaustive, never top-k-capped ----

    public function test_all_preferences_are_injected_even_beyond_top_k(): void
    {
        // top_k governs semantic discovery; preferences must bypass it entirely.
        config(['memory.top_k' => 3]);

        for ($i = 1; $i <= 8; $i++) {
            $this->createMemory([
                'content' => "Preference number {$i}",
                'category' => MemoryCategory::Preference->value,
                'importance' => 0.5,
            ]);
        }

        $context = $this->injectorWithEmptyDiscovery()->buildContext(
            agentId: $this->agent->id,
            input: 'do some task',
            teamId: $this->team->id,
        );

        $this->assertNotNull($context);
        $this->assertStringContainsString('## User Preferences', $context);
        for ($i = 1; $i <= 8; $i++) {
            $this->assertStringContainsString("Preference number {$i}", $context);
        }
    }

    public function test_preferences_block_includes_subtype_tag(): void
    {
        $this->createMemory([
            'content' => 'Always answer in JSON',
            'category' => MemoryCategory::Preference->value,
            'preference_subtype' => MemoryPreferenceSubtype::Style->value,
        ]);

        $context = $this->injectorWithEmptyDiscovery()->buildContext(
            agentId: $this->agent->id,
            input: 'task',
            teamId: $this->team->id,
        );

        $this->assertStringContainsString('[style] Always answer in JSON', $context);
    }

    public function test_rejected_and_superseded_preferences_are_not_injected(): void
    {
        $this->createMemory([
            'content' => 'Good preference kept',
            'category' => MemoryCategory::Preference->value,
        ]);
        $this->createMemory([
            'content' => 'Rejected preference hidden',
            'category' => MemoryCategory::Preference->value,
            'proposal_status' => 'rejected',
        ]);
        $this->createMemory([
            'content' => 'Superseded preference hidden',
            'category' => MemoryCategory::Preference->value,
            'belief_status' => 'superseded',
        ]);

        $context = $this->injectorWithEmptyDiscovery()->buildContext(
            agentId: $this->agent->id,
            input: 'task',
            teamId: $this->team->id,
        );

        $this->assertStringContainsString('Good preference kept', $context);
        $this->assertStringNotContainsString('Rejected preference hidden', $context);
        $this->assertStringNotContainsString('Superseded preference hidden', $context);
    }

    public function test_preferences_injection_can_be_disabled(): void
    {
        config(['memory.preferences_injection.enabled' => false]);
        $this->createMemory([
            'content' => 'A preference',
            'category' => MemoryCategory::Preference->value,
        ]);

        $context = $this->injectorWithEmptyDiscovery()->buildContext(
            agentId: $this->agent->id,
            input: 'task',
            teamId: $this->team->id,
        );

        $this->assertNull($context);
    }

    // ---- Relevance tier mapping ----

    public function test_relevance_from_cosine_maps_to_bands(): void
    {
        config(['memory.relevance_tiers.high' => 0.55, 'memory.relevance_tiers.standard' => 0.45]);

        $this->assertSame(MemoryRelevance::High, MemoryRelevance::fromCosine(0.62));
        $this->assertSame(MemoryRelevance::High, MemoryRelevance::fromCosine(0.55));
        $this->assertSame(MemoryRelevance::Standard, MemoryRelevance::fromCosine(0.50));
        $this->assertSame(MemoryRelevance::Standard, MemoryRelevance::fromCosine(0.45));
        $this->assertSame(MemoryRelevance::Low, MemoryRelevance::fromCosine(0.30));
        $this->assertNull(MemoryRelevance::fromCosine(null));
    }

    // ---- Degraded-mode telemetry ----

    public function test_degraded_modes_records_embedding_unavailable(): void
    {
        $this->app->instance(EmbeddingProviderInterface::class, new StatefulBorrowsNullEmbedding);

        $action = app(UnifiedMemorySearchAction::class);
        $action->execute(teamId: $this->team->id, query: 'anything', agentId: $this->agent->id);

        $this->assertContains('embedding_unavailable', $action->degradedModes());
    }

    public function test_degraded_modes_empty_is_accessible_before_run(): void
    {
        $this->assertSame([], app(UnifiedMemorySearchAction::class)->degradedModes());
    }

    // ---- Path B exclusions (Postgres-only: composite SQL uses EXTRACT/POWER) ----

    public function test_path_b_excludes_preferences(): void
    {
        $this->skipUnlessPgsql();

        $this->createMemory(['content' => 'A fact about the system', 'category' => MemoryCategory::Knowledge->value]);
        $this->createMemory(['content' => 'A user preference', 'category' => MemoryCategory::Preference->value]);

        $results = app(RetrieveRelevantMemoriesAction::class)->execute(
            agentId: $this->agent->id,
            query: '',
            teamId: $this->team->id,
            excludePreferences: true,
        );

        $contents = $results->pluck('content')->all();
        $this->assertContains('A fact about the system', $contents);
        $this->assertNotContains('A user preference', $contents);
    }

    public function test_provisional_excluded_from_discovery_when_flag_on(): void
    {
        $this->skipUnlessPgsql();
        config(['memory.exclude_provisional_from_discovery' => true]);

        $this->createMemory(['content' => 'Working memory item', 'tier' => MemoryTier::Working->value]);
        $this->createMemory(['content' => 'Provisional proposed item', 'tier' => MemoryTier::Proposed->value]);

        $results = app(RetrieveRelevantMemoriesAction::class)->execute(
            agentId: $this->agent->id,
            query: '',
            teamId: $this->team->id,
        );

        $contents = $results->pluck('content')->all();
        $this->assertContains('Working memory item', $contents);
        $this->assertNotContains('Provisional proposed item', $contents);
    }

    private function skipUnlessPgsql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Composite-score retrieval uses Postgres-only SQL (EXTRACT/POWER/pgvector).');
        }
    }

    private function injectorWithEmptyDiscovery(): MemoryContextInjector
    {
        // Mock the semantic discovery (Path B) to empty so the test isolates
        // the Path A preference enumeration, which runs a plain Eloquent query.
        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldReceive('execute')->andReturn(collect());

        return new MemoryContextInjector($retrieve);
    }

    private function createMemory(array $overrides): Memory
    {
        return Memory::create(array_merge([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'content' => 'memory',
            'source_type' => 'manual',
            'tier' => MemoryTier::Working->value,
            'confidence' => 0.9,
            'importance' => 0.5,
            'metadata' => [],
        ], $overrides));
    }
}

class StatefulBorrowsNullEmbedding implements EmbeddingProviderInterface
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
