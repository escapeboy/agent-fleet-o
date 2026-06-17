<?php

namespace Tests\Feature\Domain\Memory;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Memory\Actions\ExtractSuccessPatternAction;
use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Domain\Memory\Actions\StoreMemoryAction;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\Contracts\EmbeddingProviderInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Group 3 — episodic task_type recall: completed-task lessons carry a task_type
 * dimension so they can be retrieved filtered by task type.
 *
 * The SQLite test schema omits the pgvector `embedding` column, so the real
 * StoreMemoryAction (which requires an embedding) and the composite-scored
 * RetrieveRelevantMemoriesAction query can only run on Postgres. Those tests
 * skip on SQLite; the column round-trip and the extractor wiring are verified
 * SQLite-side.
 */
class MemoryEpisodicTaskTypeTest extends TestCase
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

    public function test_task_type_column_round_trips_on_model(): void
    {
        $memory = Memory::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'content' => 'episodic content',
            'source_type' => 'experiment',
            'tier' => MemoryTier::Successes->value,
            'confidence' => 0.9,
            'importance' => 0.5,
            'task_type' => 'growth',
            'metadata' => [],
        ]);

        $this->assertSame('growth', $memory->fresh()->task_type);
        $this->assertDatabaseHas('memories', [
            'id' => $memory->id,
            'task_type' => 'growth',
        ]);
    }

    public function test_store_memory_action_persists_task_type(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('StoreMemoryAction requires the pgvector embedding column (Postgres-only).');
        }

        $this->fakeEmbeddingProvider();

        $memories = app(StoreMemoryAction::class)->execute(
            teamId: $this->team->id,
            agentId: $this->agent->id,
            content: 'Lesson learned while debugging a flaky test',
            sourceType: 'experiment',
            tier: MemoryTier::Failures,
            taskType: 'debug',
        );

        $this->assertCount(1, $memories);
        $this->assertSame('debug', $memories[0]->task_type);
    }

    public function test_success_extractor_passes_task_type_from_experiment_track(): void
    {
        $experiment = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'title' => 'Improve onboarding conversion',
            'track' => ExperimentTrack::Growth,
        ]);

        $capturedArgs = null;
        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldReceive('execute')
            ->once()
            ->withArgs(function (...$args) use (&$capturedArgs) {
                $capturedArgs = $args;

                return true;
            })
            ->andReturn([]);

        $action = new ExtractSuccessPatternAction(
            $this->fakeGateway(json_encode([
                'pattern' => 'Front-load the value proposition on step one',
                'key_technique' => 'value_framing',
                'confidence' => 0.9,
                'tags' => ['pattern'],
            ])),
            $store,
        );

        $action->execute($experiment->id, $this->team->id);

        $this->assertNotNull($capturedArgs, 'StoreMemoryAction::execute was not called');
        $this->assertContains('growth', $capturedArgs, 'task_type was not forwarded to StoreMemoryAction');

        $metadata = $this->findMetadata($capturedArgs);
        $this->assertSame('growth', $metadata['task_type'] ?? null);
    }

    public function test_retrieve_filters_by_task_type(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('RetrieveRelevantMemoriesAction composite scoring is Postgres-only.');
        }

        $this->createMemory(['content' => 'debug lesson one', 'task_type' => 'debug']);
        $this->createMemory(['content' => 'growth lesson one', 'task_type' => 'growth']);

        $results = app(RetrieveRelevantMemoriesAction::class)->execute(
            agentId: $this->agent->id,
            query: '',
            scope: 'agent',
            teamId: $this->team->id,
            taskType: 'debug',
        );

        // The task_type filter is exact-match: only the 'debug' lesson is eligible,
        // the 'growth' lesson is excluded.
        $contents = $results->pluck('content')->all();
        $this->assertContains('debug lesson one', $contents);
        $this->assertNotContains('growth lesson one', $contents);
    }

    private function createMemory(array $overrides): Memory
    {
        return Memory::create(array_merge([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'content' => 'lesson',
            'source_type' => 'experiment',
            'tier' => MemoryTier::Failures->value,
            'confidence' => 0.9,
            'importance' => 0.5,
            'metadata' => [],
        ], $overrides));
    }

    /**
     * Locate the metadata array passed to StoreMemoryAction::execute. The
     * extractors stamp `extracted_at` into every metadata payload, so we use
     * that key as a uniqueness probe.
     *
     * @param  array<int, mixed>  $args
     * @return array<string, mixed>
     */
    private function findMetadata(array $args): array
    {
        foreach ($args as $arg) {
            if (is_array($arg) && array_key_exists('extracted_at', $arg)) {
                return $arg;
            }
        }

        return [];
    }

    /**
     * StoreMemoryAction skips storage when no embedding is available. Bind a
     * deterministic fake so the write gate ADDs and the row persists in tests.
     */
    private function fakeEmbeddingProvider(): void
    {
        $provider = Mockery::mock(EmbeddingProviderInterface::class);
        $provider->shouldReceive('embedForTeam')->andReturn(array_fill(0, 1536, 0.1));
        $provider->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
        $provider->shouldReceive('formatForPgvector')->andReturnUsing(
            fn (array $v) => '['.implode(',', $v).']',
        );
        $provider->shouldReceive('dimensions')->andReturn(1536);
        $provider->shouldReceive('identifier')->andReturn('fake:test');

        $this->app->instance(EmbeddingProviderInterface::class, $provider);
    }

    private function fakeGateway(string $responseJson): AiGatewayInterface
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(
            new AiResponseDTO(
                content: $responseJson,
                parsedOutput: null,
                usage: new AiUsageDTO(
                    promptTokens: 100,
                    completionTokens: 50,
                    costCredits: 0,
                ),
                provider: 'anthropic',
                model: 'claude-haiku-4-5',
                latencyMs: 10,
            ),
        );

        return $gateway;
    }
}
