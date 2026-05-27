<?php

namespace Tests\Feature\Mcp;

use App\Domain\GitRepository\Models\CodeEdge;
use App\Domain\GitRepository\Models\CodeElement;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\GitRepository\CodeImpactTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class CodeImpactToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private GitRepository $repo;

    /** @var array<string, CodeElement> */
    private array $el = [];

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

        foreach (['Caller', 'Target'] as $name) {
            $this->el[$name] = CodeElement::create([
                'team_id' => $this->team->id,
                'git_repository_id' => $this->repo->id,
                'element_type' => 'method',
                'name' => $name,
                'file_path' => "src/{$name}.ts",
                'indexed_at' => now(),
            ]);
        }

        CodeEdge::create([
            'team_id' => $this->team->id,
            'git_repository_id' => $this->repo->id,
            'source_id' => $this->el['Caller']->id,
            'target_id' => $this->el['Target']->id,
            'edge_type' => 'calls',
        ]);
    }

    private function invoke(array $args): Response
    {
        return $this->app->call([$this->app->make(CodeImpactTool::class), 'handle'], ['request' => new Request($args)]);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_lists_incoming_dependents_of_an_element(): void
    {
        $payload = $this->decode($this->invoke([
            'git_repository_id' => $this->repo->id,
            'element_id' => $this->el['Target']->id,
            'hops' => 1,
        ]));

        $this->assertSame(1, $payload['affected_count']);
        $this->assertSame('Caller', $payload['affected'][0]['name']);
        $this->assertSame('Target', $payload['element_name']);
    }

    public function test_does_not_leak_across_teams(): void
    {
        $otherTeam = Team::factory()->create();
        $otherRepo = GitRepository::create([
            'team_id' => $otherTeam->id,
            'name' => 'Other',
            'url' => 'https://github.com/acme/other',
        ]);

        // Tool is bound to $this->team; the other team's repo must be invisible.
        $response = $this->invoke([
            'git_repository_id' => $otherRepo->id,
            'element_id' => $this->el['Target']->id,
            'hops' => 1,
        ]);

        $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('error', $payload);
    }

    public function test_unknown_element_returns_structured_error(): void
    {
        $response = $this->invoke([
            'git_repository_id' => $this->repo->id,
            'element_id' => '00000000-0000-0000-0000-000000000000',
            'hops' => 1,
        ]);

        $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('error', $payload);
    }
}
