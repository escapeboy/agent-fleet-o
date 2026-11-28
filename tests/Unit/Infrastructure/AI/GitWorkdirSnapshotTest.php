<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Gateways\GitWorkdirSnapshot;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GitWorkdirSnapshotTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/git-snapshot-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();

        parent::tearDown();
    }

    public function test_take_returns_null_for_non_git_directory(): void
    {
        file_put_contents($this->dir.'/a.txt', 'x');

        $snapshot = new GitWorkdirSnapshot;

        $this->assertNull($snapshot->take($this->dir));
        $this->assertNull($snapshot->take($this->dir.'/missing'));
    }

    public function test_file_modified_without_commit_is_reported_as_worktree(): void
    {
        $this->initRepo();
        $snapshot = new GitWorkdirSnapshot;
        $before = $snapshot->take($this->dir);
        $this->assertNotNull($before);

        file_put_contents($this->dir.'/README.md', "changed\n");
        mkdir($this->dir.'/src');
        file_put_contents($this->dir.'/src/new.txt', "new\n");

        $diff = $snapshot->diff($before, $this->dir);

        $this->assertSame([], $diff['new_commits'] ?? null);
        $this->assertEqualsCanonicalizing([
            ['path' => 'README.md', 'source' => 'worktree'],
            ['path' => 'src/new.txt', 'source' => 'worktree'],
        ], $diff['changed_files'] ?? null);
        $this->assertArrayNotHasKey('changed_files_truncated', $diff);
    }

    public function test_file_dirty_before_snapshot_is_not_reported(): void
    {
        $this->initRepo();
        file_put_contents($this->dir.'/README.md', "dirty already\n");

        $snapshot = new GitWorkdirSnapshot;
        $before = $snapshot->take($this->dir);
        $this->assertNotNull($before);

        file_put_contents($this->dir.'/README.md', "dirty again\n");
        file_put_contents($this->dir.'/other.txt', "o\n");

        $diff = $snapshot->diff($before, $this->dir);

        $this->assertSame([['path' => 'other.txt', 'source' => 'worktree']], $diff['changed_files'] ?? null);
    }

    public function test_new_commit_is_listed_with_its_files(): void
    {
        $this->initRepo();
        $snapshot = new GitWorkdirSnapshot;
        $before = $snapshot->take($this->dir);
        $this->assertNotNull($before);

        file_put_contents($this->dir.'/README.md', "v2\n");
        file_put_contents($this->dir.'/b.txt', "b\n");
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '-m', 'second']);
        // Same file edited again after the commit: still listed once.
        file_put_contents($this->dir.'/b.txt', "b2\n");

        $head = trim($this->git(['rev-parse', 'HEAD']));
        $diff = $snapshot->diff($before, $this->dir);

        $this->assertSame([$head], $diff['new_commits'] ?? null);
        $this->assertSame(40, strlen($head));
        $this->assertEqualsCanonicalizing([
            ['path' => 'README.md', 'source' => 'commit'],
            ['path' => 'b.txt', 'source' => 'commit'],
        ], $diff['changed_files'] ?? null);
    }

    public function test_cap_sets_truncated_flags(): void
    {
        $this->initRepo();
        $snapshot = new GitWorkdirSnapshot(2, 2);
        $before = $snapshot->take($this->dir);
        $this->assertNotNull($before);

        foreach (['c1', 'c2', 'c3'] as $name) {
            file_put_contents($this->dir.'/'.$name.'.txt', $name);
            $this->git(['add', '-A']);
            $this->git(['commit', '-q', '-m', $name]);
        }

        $diff = $snapshot->diff($before, $this->dir);

        $this->assertCount(2, $diff['new_commits'] ?? []);
        $this->assertTrue($diff['new_commits_truncated'] ?? false);
        $this->assertCount(2, $diff['changed_files'] ?? []);
        $this->assertTrue($diff['changed_files_truncated'] ?? false);
    }

    public function test_diff_with_null_before_returns_empty(): void
    {
        $this->initRepo();

        $this->assertSame([], (new GitWorkdirSnapshot)->diff(null, $this->dir));
    }

    private function initRepo(): void
    {
        $this->git(['init', '-q', '-b', 'main']);
        $this->git(['config', 'user.email', 'test@example.com']);
        $this->git(['config', 'user.name', 'Test']);
        $this->git(['config', 'commit.gpgsign', 'false']);
        file_put_contents($this->dir.'/README.md', "init\n");
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '-m', 'init']);
    }

    /**
     * @param  list<string>  $args
     */
    private function git(array $args): string
    {
        $process = new Process(array_merge(['git'], $args), $this->dir);
        $process->mustRun();

        return $process->getOutput();
    }
}
