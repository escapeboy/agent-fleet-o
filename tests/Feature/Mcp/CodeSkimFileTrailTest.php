<?php

namespace Tests\Feature\Mcp;

use App\Domain\GitRepository\Models\CodeEdge;
use App\Domain\GitRepository\Models\CodeElement;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\GitRepository\CodeSkimFileTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

/**
 * Covers the #3 trail enhancement: code_skim_file annotates each element with
 * calls_out / called_by counts so an agent can spot hub symbols.
 */
class CodeSkimFileTrailTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private GitRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        app()->instance('mcp.team_id', $this->team->id);

        $this->repo = GitRepository::create([
            'team_id' => $this->team->id,
            'name' => 'Repo',
            'url' => 'https://github.com/acme/repo',
        ]);
    }

    private function element(string $name): CodeElement
    {
        return CodeElement::create([
            'team_id' => $this->team->id,
            'git_repository_id' => $this->repo->id,
            'element_type' => 'method',
            'name' => $name,
            'file_path' => 'src/widget.ts',
            'line_start' => 1,
            'line_end' => 5,
            'signature' => "{$name}()",
            'indexed_at' => now(),
        ]);
    }

    private function invoke(array $args): Response
    {
        return $this->app->call([$this->app->make(CodeSkimFileTool::class), 'handle'], ['request' => new Request($args)]);
    }

    public function test_skim_includes_call_trail_counts(): void
    {
        $caller = $this->element('caller');
        $callee = $this->element('callee');
        CodeEdge::create([
            'team_id' => $this->team->id,
            'git_repository_id' => $this->repo->id,
            'source_id' => $caller->id,
            'target_id' => $callee->id,
            'edge_type' => 'calls',
        ]);

        $payload = json_decode((string) $this->invoke([
            'git_repository_id' => $this->repo->id,
            'file_path' => 'src/widget.ts',
        ])->content(), true, flags: JSON_THROW_ON_ERROR);

        $byName = collect($payload['elements'])->keyBy('name');

        $this->assertSame(1, $byName['caller']['calls_out']);
        $this->assertSame(0, $byName['caller']['called_by']);
        $this->assertSame(0, $byName['callee']['calls_out']);
        $this->assertSame(1, $byName['callee']['called_by']);
    }

    public function test_trail_counts_are_zero_without_edges(): void
    {
        $this->element('lonely');

        $payload = json_decode((string) $this->invoke([
            'git_repository_id' => $this->repo->id,
            'file_path' => 'src/widget.ts',
        ])->content(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $payload['elements'][0]['calls_out']);
        $this->assertSame(0, $payload['elements'][0]['called_by']);
    }
}
