<?php

namespace Tests\Feature\Domain\GitRepository;

use App\Domain\GitRepository\Actions\IndexRepositoryAction;
use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\DTOs\ExtractedEdge;
use App\Domain\GitRepository\DTOs\ExtractedElement;
use App\Domain\GitRepository\DTOs\ExtractionResult;
use App\Domain\GitRepository\Models\CodeEdge;
use App\Domain\GitRepository\Models\CodeElement;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\GitRepository\Services\PhpCodeParser;
use App\Domain\GitRepository\Services\PolyglotCodeExtractor;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Services\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IndexRepositoryActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private GitRepository $repository;

    private const PHP_FILE = <<<'PHP'
        <?php

        namespace App;

        class Calculator
        {
            public function add(int $a, int $b): int
            {
                return $a + $b;
            }
        }
        PHP;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $this->repository = GitRepository::create([
            'team_id' => $this->team->id,
            'name' => 'Test Repo',
            'url' => 'https://github.com/acme/test',
        ]);
    }

    private function action(EmbeddingService $embeddings, ?PolyglotCodeExtractor $polyglot = null): IndexRepositoryAction
    {
        $client = Mockery::mock(GitClientInterface::class);
        $client->shouldReceive('getFileTree')
            ->andReturn([['path' => 'src/Calculator.php', 'type' => 'blob', 'sha' => 'abc']]);
        $client->shouldReceive('readFile')
            ->with('src/Calculator.php')
            ->andReturn(self::PHP_FILE);

        $router = Mockery::mock(GitOperationRouter::class);
        $router->shouldReceive('resolve')->andReturn($client);

        // Default: a real extractor — a no-op because the polyglot flag is off
        // and no binary is present in CI, so the PHP-only path is unchanged.
        $polyglot ??= app(PolyglotCodeExtractor::class);

        return new IndexRepositoryAction(app(PhpCodeParser::class), $router, $embeddings, $polyglot);
    }

    public function test_indexes_php_file_into_code_elements(): void
    {
        $embeddings = Mockery::mock(EmbeddingService::class);
        $embeddings->shouldReceive('embedForTeam')->andReturn(null);

        $this->action($embeddings)->execute($this->repository);

        $elements = CodeElement::where('git_repository_id', $this->repository->id)->get();

        $this->assertTrue($elements->contains('element_type', 'file'));
        $this->assertTrue($elements->contains('name', 'Calculator'));
        $this->assertTrue($elements->contains('name', 'add'));
        $this->assertSame('indexed', $this->repository->fresh()->indexing_status);
    }

    public function test_embeds_each_parsed_element_and_survives_missing_pgvector_column(): void
    {
        // The pgvector column is absent on the SQLite test DB — the embedding
        // UPDATE must be caught so indexing still completes end to end.
        $embeddings = Mockery::mock(EmbeddingService::class);
        $embeddings->shouldReceive('embedForTeam')
            ->twice() // class + method (the 'file' sentinel is not embedded)
            ->andReturn([0.1, 0.2, 0.3]);
        $embeddings->shouldReceive('formatForPgvector')->andReturn('[0.1,0.2,0.3]');

        $this->action($embeddings)->execute($this->repository);

        $this->assertSame('indexed', $this->repository->fresh()->indexing_status);
        $this->assertSame(
            2,
            CodeElement::where('git_repository_id', $this->repository->id)
                ->whereIn('element_type', ['class', 'method'])
                ->count(),
        );
    }

    public function test_indexing_completes_when_no_embedding_key_is_available(): void
    {
        $embeddings = Mockery::mock(EmbeddingService::class);
        $embeddings->shouldReceive('embedForTeam')->andReturn(null);
        $embeddings->shouldNotReceive('formatForPgvector');

        $this->action($embeddings)->execute($this->repository);

        $this->assertSame('indexed', $this->repository->fresh()->indexing_status);
    }

    public function test_polyglot_pass_persists_non_php_elements_and_edges(): void
    {
        $embeddings = Mockery::mock(EmbeddingService::class);
        $embeddings->shouldReceive('embedForTeam')->andReturn(null);

        $polyglot = Mockery::mock(PolyglotCodeExtractor::class);
        $polyglot->shouldReceive('extract')->andReturn(new ExtractionResult(
            elements: [
                new ExtractedElement('class:ts1', 'class', 'Widget', 'src/widget.ts', 1, 20, 'class Widget', null, 'typescript'),
                new ExtractedElement('method:ts2', 'method', 'render', 'src/widget.ts', 5, 10, 'render(): void', null, 'typescript'),
            ],
            edges: [
                new ExtractedEdge('method:ts2', 'class:ts1', 'calls'),
            ],
        ));

        $this->action($embeddings, $polyglot)->execute($this->repository);

        // Non-PHP elements landed alongside the PHP ones.
        $this->assertDatabaseHas('code_elements', [
            'git_repository_id' => $this->repository->id,
            'name' => 'Widget',
            'element_type' => 'class',
            'file_path' => 'src/widget.ts',
        ]);
        // PHP elements from the nikic pass are untouched.
        $this->assertTrue(
            CodeElement::where('git_repository_id', $this->repository->id)->where('name', 'Calculator')->exists(),
        );

        // The edge was resolved through the graph-id → uuid map and persisted.
        $source = CodeElement::where('name', 'render')->firstOrFail();
        $target = CodeElement::where('name', 'Widget')->firstOrFail();
        $this->assertDatabaseHas('code_edges', [
            'git_repository_id' => $this->repository->id,
            'source_id' => $source->id,
            'target_id' => $target->id,
            'edge_type' => 'calls',
        ]);
    }

    public function test_polyglot_pass_replaces_prior_non_php_elements_but_keeps_php(): void
    {
        // A stale non-PHP element from a previous index run.
        $stale = CodeElement::create([
            'team_id' => $this->team->id,
            'git_repository_id' => $this->repository->id,
            'element_type' => 'class',
            'name' => 'StaleClass',
            'file_path' => 'src/stale.ts',
            'indexed_at' => now(),
        ]);

        $embeddings = Mockery::mock(EmbeddingService::class);
        $embeddings->shouldReceive('embedForTeam')->andReturn(null);

        $polyglot = Mockery::mock(PolyglotCodeExtractor::class);
        $polyglot->shouldReceive('extract')->andReturn(new ExtractionResult(
            elements: [
                new ExtractedElement('class:ts1', 'class', 'FreshClass', 'src/fresh.ts', 1, 5, 'class FreshClass', null, 'typescript'),
            ],
            edges: [],
        ));

        $this->action($embeddings, $polyglot)->execute($this->repository);

        $this->assertDatabaseMissing('code_elements', ['id' => $stale->id]);
        $this->assertTrue(CodeElement::where('name', 'FreshClass')->exists());
        $this->assertTrue(CodeElement::where('name', 'Calculator')->exists());
    }

    public function test_polyglot_failure_does_not_fail_the_php_index(): void
    {
        $embeddings = Mockery::mock(EmbeddingService::class);
        $embeddings->shouldReceive('embedForTeam')->andReturn(null);

        $polyglot = Mockery::mock(PolyglotCodeExtractor::class);
        $polyglot->shouldReceive('extract')->andThrow(new \RuntimeException('codegraph boom'));

        $this->action($embeddings, $polyglot)->execute($this->repository);

        // PHP index still completed despite the polyglot pass throwing.
        $this->assertSame('indexed', $this->repository->fresh()->indexing_status);
        $this->assertTrue(CodeElement::where('name', 'Calculator')->exists());
        $this->assertSame(0, CodeEdge::where('git_repository_id', $this->repository->id)->count());
    }
}
