<?php

namespace Tests\Unit\Domain\GitRepository;

use App\Domain\GitRepository\Models\CodeEdge;
use App\Domain\GitRepository\Models\CodeElement;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\CodeGraphTraversal;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Covers outgoing (existing) and incoming (new — impact) traversal directions on
 * the SQLite BFS path. Graph: A --calls--> B --calls--> C, plus X --inherits--> B.
 */
class CodeGraphTraversalTest extends TestCase
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
        $this->repo = GitRepository::create([
            'team_id' => $this->team->id,
            'name' => 'Repo',
            'url' => 'https://github.com/acme/repo',
        ]);

        foreach (['A', 'B', 'C', 'X'] as $name) {
            $this->el[$name] = CodeElement::create([
                'team_id' => $this->team->id,
                'git_repository_id' => $this->repo->id,
                'element_type' => 'method',
                'name' => $name,
                'file_path' => "src/{$name}.ts",
                'indexed_at' => now(),
            ]);
        }

        $this->edge('A', 'B', 'calls');
        $this->edge('B', 'C', 'calls');
        $this->edge('X', 'B', 'inherits');
    }

    private function edge(string $from, string $to, string $type): void
    {
        CodeEdge::create([
            'team_id' => $this->team->id,
            'git_repository_id' => $this->repo->id,
            'source_id' => $this->el[$from]->id,
            'target_id' => $this->el[$to]->id,
            'edge_type' => $type,
        ]);
    }

    private function names(Collection $c): array
    {
        return $c->pluck('name')->sort()->values()->all();
    }

    public function test_outgoing_traversal_follows_targets(): void
    {
        $out = (new CodeGraphTraversal)->traverse($this->team->id, $this->el['A']->id, 2, null, 'out');

        // A reaches B (hop 1) and C (hop 2) via calls.
        $this->assertSame(['B', 'C'], $this->names($out));
    }

    public function test_incoming_traversal_finds_dependents(): void
    {
        $in = (new CodeGraphTraversal)->traverse($this->team->id, $this->el['C']->id, 2, null, 'in');

        // Unfiltered reverse walk from C: hop 1 → B (calls C); hop 2 from B → A
        // (calls B) and X (inherits B). All three depend transitively on C.
        $this->assertSame(['A', 'B', 'X'], $this->names($in));
    }

    public function test_incoming_traversal_respects_edge_type_filter(): void
    {
        $callers = (new CodeGraphTraversal)->traverse($this->team->id, $this->el['B']->id, 1, 'calls', 'in');
        $this->assertSame(['A'], $this->names($callers), 'calls-filtered reverse walk must ignore the inherits edge from X');

        $heirs = (new CodeGraphTraversal)->traverse($this->team->id, $this->el['B']->id, 1, 'inherits', 'in');
        $this->assertSame(['X'], $this->names($heirs));
    }
}
