<?php

namespace Tests\Feature\Domain\GitRepository;

use App\Domain\GitRepository\Actions\IndexRepositoryAction;
use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\CodeElement;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\GitRepository\Services\PhpCodeParser;
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

    private function action(EmbeddingService $embeddings): IndexRepositoryAction
    {
        $client = Mockery::mock(GitClientInterface::class);
        $client->shouldReceive('getFileTree')
            ->andReturn([['path' => 'src/Calculator.php', 'type' => 'blob', 'sha' => 'abc']]);
        $client->shouldReceive('readFile')
            ->with('src/Calculator.php')
            ->andReturn(self::PHP_FILE);

        $router = Mockery::mock(GitOperationRouter::class);
        $router->shouldReceive('resolve')->andReturn($client);

        return new IndexRepositoryAction(app(PhpCodeParser::class), $router, $embeddings);
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
}
