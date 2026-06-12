<?php

namespace Tests\Feature\Domain\Memory;

use App\Domain\Agent\Models\Agent;
use App\Domain\KnowledgeGraph\Services\TemporalKnowledgeGraphService;
use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Domain\Memory\Actions\UnifiedMemorySearchAction;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\EmbeddingProviderInterface;
use App\Mcp\Tools\Memory\MemoryRetrievalBenchmarkTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Tests\TestCase;

class RetrievalBenchmarkCommandTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $this->agent = Agent::factory()->for($this->team)->create();

        $this->app->instance(EmbeddingProviderInterface::class, new FakeEmbeddingProvider);

        // Deterministic search stand-in: "retrieves" any benchmark memory whose
        // content contains the query string, so metric values are predictable
        // regardless of DB driver (no pgvector / FTS on SQLite).
        $this->app->instance(UnifiedMemorySearchAction::class, new class(app(RetrieveRelevantMemoriesAction::class), app(TemporalKnowledgeGraphService::class)) extends UnifiedMemorySearchAction
        {
            public function execute(
                string $teamId,
                string $query,
                ?string $agentId = null,
                ?string $projectId = null,
                int $topK = 10,
                ?array $tags = null,
                ?string $topic = null,
            ): Collection {
                return Memory::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->where('source_type', 'benchmark')
                    ->where('content', 'like', '%'.$query.'%')
                    ->orderBy('id')
                    ->limit($topK)
                    ->get()
                    ->map(fn (Memory $memory) => [
                        'type' => 'memory',
                        'content' => $memory->content,
                        'score' => 1.0,
                        'metadata' => ['id' => $memory->id],
                    ])
                    ->values();
            }
        });
    }

    private function writeDataset(array $dataset): string
    {
        $path = sys_get_temp_dir().'/retrieval_benchmark_test_'.uniqid().'.json';
        file_put_contents($path, json_encode($dataset));

        return $path;
    }

    private function validDataset(): array
    {
        return [
            'name' => 'test-set',
            'documents' => [
                ['key' => 'alpha', 'content' => 'alphatoken deployment facts'],
                ['key' => 'beta', 'content' => 'betatoken budget facts'],
                ['key' => 'gamma', 'content' => 'gammatoken queue facts'],
            ],
            'cases' => [
                ['query' => 'alphatoken', 'relevant' => ['alpha']],
                ['query' => 'betatoken', 'relevant' => ['beta']],
            ],
        ];
    }

    public function test_happy_path_reports_perfect_scores_for_exact_matches(): void
    {
        $path = $this->writeDataset($this->validDataset());

        $this->artisan('memory:benchmark-retrieval', [
            'dataset' => $path,
            '--team' => $this->team->id,
            '--agent' => $this->agent->id,
        ])
            ->expectsOutputToContain('Means over 2 case(s)')
            ->expectsOutputToContain('1.000')
            ->assertSuccessful();
    }

    public function test_fixture_memories_are_cleaned_up_unless_kept(): void
    {
        $path = $this->writeDataset($this->validDataset());
        $benchmarkMemories = fn () => Memory::withoutGlobalScopes()->where('source_type', 'benchmark')->count();

        $this->artisan('memory:benchmark-retrieval', [
            'dataset' => $path,
            '--team' => $this->team->id,
            '--agent' => $this->agent->id,
        ])->assertSuccessful();

        $this->assertSame(0, $benchmarkMemories());

        $this->artisan('memory:benchmark-retrieval', [
            'dataset' => $path,
            '--team' => $this->team->id,
            '--agent' => $this->agent->id,
            '--keep' => true,
        ])->assertSuccessful();

        $this->assertSame(3, $benchmarkMemories());
    }

    public function test_missing_dataset_file_fails_without_side_effects(): void
    {
        $this->artisan('memory:benchmark-retrieval', [
            'dataset' => '/nonexistent/dataset.json',
            '--team' => $this->team->id,
            '--agent' => $this->agent->id,
        ])->assertFailed();

        $this->assertSame(0, Memory::withoutGlobalScopes()->where('source_type', 'benchmark')->count());
    }

    public function test_malformed_dataset_without_cases_fails_with_validation_message(): void
    {
        $path = $this->writeDataset([
            'documents' => [['key' => 'a', 'content' => 'something']],
        ]);

        $this->artisan('memory:benchmark-retrieval', [
            'dataset' => $path,
            '--team' => $this->team->id,
            '--agent' => $this->agent->id,
        ])
            ->expectsOutputToContain('Invalid dataset')
            ->assertFailed();
    }

    public function test_json_output_contains_full_report(): void
    {
        $path = $this->writeDataset($this->validDataset());

        $this->artisan('memory:benchmark-retrieval', [
            'dataset' => $path,
            '--team' => $this->team->id,
            '--agent' => $this->agent->id,
            '--json' => true,
        ])
            ->expectsOutputToContain('"means"')
            ->assertSuccessful();
    }

    public function test_mcp_tool_runs_bundled_dataset_for_team(): void
    {
        app()->instance('mcp.team_id', $this->team->id);

        $response = (new MemoryRetrievalBenchmarkTool)->handle(new Request(['k' => 5]));
        $payload = json_decode((string) $response->content(), true);

        $this->assertFalse($response->isError(), 'Got error: '.json_encode($payload));
        $this->assertSame('retrieval-smoke-v1', $payload['name']);
        $this->assertArrayHasKey('recall', $payload['means']);
        $this->assertCount(10, $payload['cases']);
        // Fixtures must not linger after the MCP run either.
        $this->assertSame(0, Memory::withoutGlobalScopes()->where('source_type', 'benchmark')->count());
    }

    public function test_mcp_tool_rejects_paths_outside_benchmarks_dir(): void
    {
        app()->instance('mcp.team_id', $this->team->id);

        $response = (new MemoryRetrievalBenchmarkTool)->handle(new Request(['dataset' => '../../.env']));

        $this->assertTrue($response->isError());
    }
}

class FakeEmbeddingProvider implements EmbeddingProviderInterface
{
    public function embed(string $text): array
    {
        return array_fill(0, 8, 0.1);
    }

    public function embedForTeam(string $text, ?string $teamId): ?array
    {
        return $this->embed($text);
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
        return 'fake-benchmark';
    }
}
