<?php

namespace Tests\Unit\Domain\GitRepository;

use App\Domain\GitRepository\Models\CodeElement;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\CodeRetriever;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Services\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Covers the keyword-search path of CodeRetriever. The hybrid semantic path is
 * pgvector-only and not exercised on the SQLite test DB (same constraint as
 * MemorySearchTool::semanticSearch).
 */
class CodeRetrieverTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private GitRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $this->repository = GitRepository::create([
            'team_id' => $this->team->id,
            'name' => 'Repo',
            'url' => 'https://github.com/acme/repo',
        ]);
    }

    private function element(string $name, array $overrides = []): CodeElement
    {
        return CodeElement::create(array_merge([
            'team_id' => $this->team->id,
            'git_repository_id' => $this->repository->id,
            'element_type' => 'class',
            'name' => $name,
            'file_path' => "src/{$name}.php",
            'content_hash' => hash('sha256', $name),
            'indexed_at' => now(),
        ], $overrides));
    }

    private function retriever(): CodeRetriever
    {
        // Keyword search never touches the embedding service on SQLite.
        return new CodeRetriever(Mockery::mock(EmbeddingService::class));
    }

    public function test_keyword_search_matches_elements_by_name(): void
    {
        $this->element('InvoiceCalculator');
        $this->element('UserRepository');

        $results = $this->retriever()->search($this->team->id, $this->repository->id, 'InvoiceCalculator');

        $this->assertCount(1, $results);
        $this->assertSame('InvoiceCalculator', $results->first()->name);
    }

    public function test_keyword_search_excludes_file_sentinel_elements(): void
    {
        $this->element('PaymentService');
        $this->element('PaymentService.php', ['element_type' => 'file']);

        $results = $this->retriever()->search($this->team->id, $this->repository->id, 'PaymentService');

        $this->assertCount(1, $results);
        $this->assertSame('class', $results->first()->element_type);
    }

    public function test_keyword_search_does_not_leak_across_teams_or_repos(): void
    {
        $this->element('SharedName');

        $otherTeam = Team::factory()->create();
        $otherRepo = GitRepository::create([
            'team_id' => $otherTeam->id,
            'name' => 'Other',
            'url' => 'https://github.com/acme/other',
        ]);
        CodeElement::create([
            'team_id' => $otherTeam->id,
            'git_repository_id' => $otherRepo->id,
            'element_type' => 'class',
            'name' => 'SharedName',
            'file_path' => 'src/SharedName.php',
            'content_hash' => hash('sha256', 'other'),
            'indexed_at' => now(),
        ]);

        $results = $this->retriever()->search($this->team->id, $this->repository->id, 'SharedName');

        $this->assertCount(1, $results);
        $this->assertSame($this->team->id, $results->first()->team_id);
    }
}
