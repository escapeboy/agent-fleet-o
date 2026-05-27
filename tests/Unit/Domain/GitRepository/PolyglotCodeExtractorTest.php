<?php

namespace Tests\Unit\Domain\GitRepository;

use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\PolyglotCodeExtractor;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Covers the pure mapping core (CodeGraph SQLite → FleetQ elements/edges) against
 * a hand-built fixture database, plus the graceful no-op when the binary or flag
 * is absent. The shell-out path (codegraph index) is exercised only in the manual
 * ops checklist — the binary is not present in CI.
 */
class PolyglotCodeExtractorTest extends TestCase
{
    use RefreshDatabase;

    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir().'/codegraph_fixture_'.bin2hex(random_bytes(6)).'.db';
        $this->buildFixture($this->dbPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
        parent::tearDown();
    }

    private function buildFixture(string $path): void
    {
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE nodes (id TEXT PRIMARY KEY, kind TEXT, name TEXT, qualified_name TEXT, file_path TEXT, language TEXT, start_line INT, end_line INT, signature TEXT, docstring TEXT)');
        $pdo->exec('CREATE TABLE edges (id INTEGER PRIMARY KEY, source TEXT, target TEXT, kind TEXT)');

        $nodes = [
            // [id, kind, name, file, language, signature]
            ['class:c1', 'class', 'Widget', 'src/widget.ts', 'typescript', 'class Widget'],
            ['interface:i1', 'interface', 'Drawable', 'src/widget.ts', 'typescript', 'interface Drawable'],
            ['method:m1', 'method', 'render', 'src/widget.ts', 'typescript', 'render(): void'],
            ['function:f1', 'function', 'helper', 'src/util.ts', 'typescript', 'helper()'],
            ['import:x1', 'import', 'react', 'src/widget.ts', 'typescript', null],     // skipped (kind)
            ['variable:v1', 'variable', 'config', 'src/widget.ts', 'typescript', null], // skipped (kind)
            ['class:php1', 'class', 'PhpThing', 'src/Thing.php', 'php', 'class PhpThing'], // skipped (php)
        ];
        $ins = $pdo->prepare('INSERT INTO nodes (id,kind,name,qualified_name,file_path,language,start_line,end_line,signature,docstring) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($nodes as $n) {
            $ins->execute([$n[0], $n[1], $n[2], $n[2], $n[3], $n[4], 1, 9, $n[5], null]);
        }

        $edges = [
            ['method:m1', 'class:c1', 'calls'],          // calls → calls
            ['class:c1', 'interface:i1', 'implements'],   // implements → inherits
            ['method:m1', 'function:f1', 'instantiates'], // instantiates → calls
            ['method:m1', 'import:x1', 'imports'],         // dropped (target skipped)
            ['class:c1', 'method:m1', 'contains'],         // dropped (noise kind)
        ];
        $ie = $pdo->prepare('INSERT INTO edges (source,target,kind) VALUES (?,?,?)');
        foreach ($edges as $e) {
            $ie->execute($e);
        }
    }

    public function test_map_database_normalizes_kinds_and_skips_noise(): void
    {
        $result = (new PolyglotCodeExtractor)->mapDatabase($this->dbPath);

        $names = collect($result->elements)->pluck('elementType', 'name')->all();

        // interface → class; method/function/class kept; import/variable/php dropped.
        $this->assertSame('class', $names['Widget']);
        $this->assertSame('class', $names['Drawable']);
        $this->assertSame('method', $names['render']);
        $this->assertSame('function', $names['helper']);
        $this->assertArrayNotHasKey('react', $names);
        $this->assertArrayNotHasKey('config', $names);
        $this->assertArrayNotHasKey('PhpThing', $names, 'PHP nodes must be skipped — owned by PhpCodeParser');
        $this->assertCount(4, $result->elements);
    }

    public function test_map_database_normalizes_and_filters_edges(): void
    {
        $result = (new PolyglotCodeExtractor)->mapDatabase($this->dbPath);

        $types = collect($result->edges)->pluck('edgeType')->sort()->values()->all();

        // calls, instantiates→calls, implements→inherits = 3 kept.
        // imports-to-import-node dropped (target skipped); contains dropped (noise).
        $this->assertSame(['calls', 'calls', 'inherits'], $types);
        $this->assertCount(3, $result->edges);
    }

    public function test_rejects_unsafe_relative_paths(): void
    {
        $extractor = new PolyglotCodeExtractor;

        // Safe.
        $this->assertTrue($extractor->isSafeRelativePath('src/app/Widget.ts'));
        $this->assertTrue($extractor->isSafeRelativePath('a/b/c.py'));

        // Unsafe: traversal, absolute, null byte, dot segments, empty.
        $this->assertFalse($extractor->isSafeRelativePath('../../etc/cron.d/x'));
        $this->assertFalse($extractor->isSafeRelativePath('a/../../b.ts'));
        $this->assertFalse($extractor->isSafeRelativePath('/etc/passwd'));
        $this->assertFalse($extractor->isSafeRelativePath("a/b\0.ts"));
        $this->assertFalse($extractor->isSafeRelativePath('./x.ts'));
        $this->assertFalse($extractor->isSafeRelativePath('a/..'));
        $this->assertFalse($extractor->isSafeRelativePath(''));
    }

    public function test_extract_is_noop_without_binary_or_flag(): void
    {
        config(['git_repository.polyglot_index' => false]);

        $team = Team::factory()->create();
        $repo = GitRepository::create([
            'team_id' => $team->id,
            'name' => 'Repo',
            'url' => 'https://github.com/acme/repo',
        ]);
        $client = Mockery::mock(GitClientInterface::class);
        $client->shouldNotReceive('getFileTree');

        $result = (new PolyglotCodeExtractor)->extract($repo, $client);

        $this->assertTrue($result->isEmpty());
    }
}
