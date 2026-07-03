<?php

namespace Tests\Feature\Domain\GitRepository;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\WarmRepoManager;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Real-git integration tests for the warm build sandbox: clone once, then
 * fetch; isolated, pristine worktree per run.
 */
class WarmRepoManagerTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp = '';

    private string $bare = '';

    private string $seed = '';

    private ?Team $team = null;

    private ?WarmRepoManager $mgr = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Process::run(['git', '--version'])->successful()) {
            $this->markTestSkipped('git binary not available in this environment');
        }

        $this->tmp = sys_get_temp_dir().'/wrm-'.Str::random(10);
        File::makeDirectory($this->tmp, 0777, true);
        config(['experiments.warm_build.base_dir' => $this->tmp.'/warm']);

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T', 'slug' => 't-'.Str::random(6),
            'owner_id' => $user->id, 'settings' => [],
        ]);

        $this->bare = $this->tmp.'/remote.git';
        $this->seed = $this->tmp.'/seed';
        $this->initRemote();

        $this->mgr = app(WarmRepoManager::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmp) && is_dir($this->tmp)) {
            File::deleteDirectory($this->tmp);
        }
        parent::tearDown();
    }

    private function git(array $args): void
    {
        Process::run(array_merge(['git'], $args))->throw();
    }

    private function initRemote(): void
    {
        File::makeDirectory($this->seed, 0777, true);
        $this->git(['init', '--bare', '-b', 'main', $this->bare]);
        $this->git(['-C', $this->seed, 'init', '-b', 'main']);
        $this->git(['-C', $this->seed, 'config', 'user.email', 't@example.com']);
        $this->git(['-C', $this->seed, 'config', 'user.name', 'Test']);
        File::put($this->seed.'/README.md', 'v1');
        $this->git(['-C', $this->seed, 'add', '-A']);
        $this->git(['-C', $this->seed, 'commit', '-m', 'seed']);
        $this->git(['-C', $this->seed, 'remote', 'add', 'origin', $this->bare]);
        $this->git(['-C', $this->seed, 'push', 'origin', 'main']);
    }

    private function pushSeedCommit(string $file, string $content): void
    {
        File::put($this->seed.'/'.$file, $content);
        $this->git(['-C', $this->seed, 'add', '-A']);
        $this->git(['-C', $this->seed, 'commit', '-m', 'add '.$file]);
        $this->git(['-C', $this->seed, 'push', 'origin', 'main']);
    }

    private function repo(): GitRepository
    {
        return GitRepository::create([
            'team_id' => $this->team->id,
            'name' => 'r',
            'url' => $this->bare,
        ]);
    }

    private function basePath(GitRepository $repo): string
    {
        return $this->tmp.'/warm/'.$repo->team_id.'/'.$repo->id;
    }

    public function test_first_checkout_clones_base_and_returns_worktree_at_ref(): void
    {
        $repo = $this->repo();
        $wt = $this->mgr->checkout($repo, 'origin/main', 'run-aaaaaaaa');

        $this->assertDirectoryExists($wt);
        $this->assertFileExists($wt.'/README.md');
        $this->assertSame('v1', File::get($wt.'/README.md'));
        $this->assertDirectoryExists($this->basePath($repo).'/.git');
    }

    public function test_second_checkout_fetches_not_reclones(): void
    {
        $repo = $this->repo();
        $this->mgr->checkout($repo, 'origin/main', 'run-1');

        // Sentinel inside the base .git — survives a fetch, gone if re-cloned.
        $sentinel = $this->basePath($repo).'/.git/FLEETQ_SENTINEL';
        File::put($sentinel, 'x');

        $this->pushSeedCommit('v2.txt', 'v2');
        $wt2 = $this->mgr->checkout($repo, 'origin/main', 'run-2');

        $this->assertFileExists($sentinel, 'base was re-cloned (clone-once violated)');
        $this->assertFileExists($wt2.'/v2.txt', 'fetch did not pick up the new commit');
    }

    public function test_worktree_is_pristine_on_reuse_discarding_dirty_state(): void
    {
        $repo = $this->repo();
        $wt = $this->mgr->checkout($repo, 'origin/main', 'run-reuse');
        File::put($wt.'/stray.txt', 'dirt');
        File::put($wt.'/README.md', 'tampered');

        $wt2 = $this->mgr->checkout($repo, 'origin/main', 'run-reuse');

        $this->assertFileDoesNotExist($wt2.'/stray.txt');
        $this->assertSame('v1', File::get($wt2.'/README.md'));
    }

    public function test_two_runs_get_isolated_worktrees(): void
    {
        $repo = $this->repo();
        $a = $this->mgr->checkout($repo, 'origin/main', 'run-aaa');
        $b = $this->mgr->checkout($repo, 'origin/main', 'run-bbb');

        $this->assertNotSame($a, $b);
        File::put($a.'/only-a.txt', '1');
        $this->assertFileDoesNotExist($b.'/only-a.txt');
    }

    public function test_same_timestamp_prefix_runs_get_isolated_worktrees(): void
    {
        // UUIDv7 ids minted in the same batch share their leading 8 hex chars. The
        // worktree slot MUST key on the full id; otherwise concurrent same-batch
        // runs collide on one worktree and clobber each other mid-build — the
        // observed "cannot change to worktree: No such file or directory" failure.
        $repo = $this->repo();
        $a = $this->mgr->checkout($repo, 'origin/main', '019f2736-531b-71d1-b97e-a6784b26e3a4');
        $b = $this->mgr->checkout($repo, 'origin/main', '019f2736-5435-70ce-844c-8dca97af1a8b');

        $this->assertNotSame($a, $b, 'same-prefix runs must not share a worktree slot');
        File::put($a.'/only-a.txt', '1');
        $this->assertDirectoryExists($a, 'run A worktree was clobbered by run B (collision)');
        $this->assertFileExists($a.'/only-a.txt');
        $this->assertFileDoesNotExist($b.'/only-a.txt');
    }

    public function test_checkout_at_specific_commit_sha(): void
    {
        $repo = $this->repo();
        $firstSha = trim(Process::run(['git', '-C', $this->seed, 'rev-parse', 'HEAD'])->output());
        $this->pushSeedCommit('later.txt', 'later');

        $wt = $this->mgr->checkout($repo, $firstSha, 'run-sha');

        $this->assertFileDoesNotExist($wt.'/later.txt');
        $this->assertSame($firstSha, trim(Process::run(['git', '-C', $wt, 'rev-parse', 'HEAD'])->output()));
    }

    public function test_release_removes_worktree_but_keeps_base(): void
    {
        $repo = $this->repo();
        $wt = $this->mgr->checkout($repo, 'origin/main', 'run-rel');

        $this->mgr->release($repo, $wt);

        $this->assertDirectoryDoesNotExist($wt);
        $this->assertDirectoryExists($this->basePath($repo).'/.git');
    }

    public function test_prune_removes_stale_worktrees_by_age_never_in_flight(): void
    {
        $repo = $this->repo();
        $stale = $this->mgr->checkout($repo, 'origin/main', 'run-stale');
        $fresh = $this->mgr->checkout($repo, 'origin/main', 'run-fresh');

        // Age the stale worktree well past the TTL; the fresh one stands in for an
        // actively-building run that a sibling's prune must never remove.
        touch($stale, time() - 10000);

        $removed = $this->mgr->prune($repo, ttlSeconds: 3600);

        $this->assertSame(1, $removed);
        $this->assertDirectoryDoesNotExist($stale);
        $this->assertDirectoryExists($fresh, 'a fresh (in-flight) worktree must never be pruned');
    }

    public function test_enabled_reflects_config(): void
    {
        config(['experiments.warm_build.enabled' => true]);
        $this->assertTrue(WarmRepoManager::enabled());
        config(['experiments.warm_build.enabled' => false]);
        $this->assertFalse(WarmRepoManager::enabled());
    }
}
