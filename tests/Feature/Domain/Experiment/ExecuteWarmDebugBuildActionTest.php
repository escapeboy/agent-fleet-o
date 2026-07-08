<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Experiment\Actions\ExecuteWarmDebugBuildAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Exceptions\VpsLocalAgentException;
use App\Infrastructure\AI\Gateways\LocalAgentGateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Real-git integration for the platform-side warm-build debug builder: the agent
 * (faked) edits the worktree, the action commits + pushes to a bare origin and
 * opens a (faked) draft PR, then completes the building stage.
 */
class ExecuteWarmDebugBuildActionTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp = '';

    private string $bare = '';

    private string $seed = '';

    private ?Team $team = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Process::run(['git', '--version'])->successful()) {
            $this->markTestSkipped('git binary not available in this environment');
        }

        $this->tmp = sys_get_temp_dir().'/wbld-'.Str::random(10);
        File::makeDirectory($this->tmp, 0777, true);
        config(['experiments.warm_build.base_dir' => $this->tmp.'/warm']);
        config(['experiments.warm_build.enabled' => true]);

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T', 'slug' => 't-'.Str::random(6),
            'owner_id' => $user->id, 'settings' => [],
        ]);

        // Isolate the action's orchestration from transition side-effects
        // (DispatchNextStageJob et al. have their own tests).
        Event::fake([ExperimentTransitioned::class]);

        $this->bare = $this->tmp.'/remote.git';
        $this->seed = $this->tmp.'/seed';
        $this->initRemote();
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

    private function repo(): GitRepository
    {
        return GitRepository::create([
            'team_id' => $this->team->id,
            'name' => 'r',
            'url' => $this->bare,
            'default_branch' => 'main',
        ]);
    }

    private function experiment(array $constraints): Experiment
    {
        $exp = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'track' => ExperimentTrack::Debug,
            'status' => ExperimentStatus::Building,
            'constraints' => $constraints,
            'title' => 'Null deref in checkout',
        ]);

        ExperimentStage::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => $exp->id,
            'stage' => StageType::Building,
            'status' => StageStatus::Running,
            'started_at' => now(),
        ]);

        return $exp;
    }

    /** Fake the VPS agent: on complete() it edits a file in the worktree. */
    private function fakeAgentWriting(?string $file, string $content = 'patched'): void
    {
        $gw = Mockery::mock(LocalAgentGateway::class);
        $gw->shouldReceive('complete')->andReturnUsing(function ($request) use ($file, $content) {
            if ($file !== null) {
                File::put($request->workingDirectory.'/'.$file, $content);
            }

            return new AiResponseDTO(
                content: 'done', parsedOutput: null, usage: new AiUsageDTO(0, 0, 0),
                provider: 'claude-code-vps', model: '', latencyMs: 1,
            );
        });
        $this->app->instance(LocalAgentGateway::class, $gw);
    }

    /**
     * Fake the VPS agent per best-of-N call: invocation i writes the files in
     * $filesPerCall[i] (['relative/path' => content]) into that candidate's own
     * worktree. A null entry means "throw" (candidate failure); pass throwOn to
     * throw on a specific call index instead.
     *
     * @param  list<array<string,string>>  $filesPerCall
     */
    private function fakeAgentPerCall(array $filesPerCall, ?int $throwOn = null): void
    {
        $i = 0;
        $gw = Mockery::mock(LocalAgentGateway::class);
        $gw->shouldReceive('complete')->andReturnUsing(function ($request) use (&$i, $filesPerCall, $throwOn) {
            $call = $i;
            $i++;
            if ($throwOn !== null && $call === $throwOn) {
                throw new \RuntimeException('agent crashed on candidate '.$call);
            }
            foreach ($filesPerCall[$call] ?? [] as $rel => $content) {
                $path = $request->workingDirectory.'/'.$rel;
                File::ensureDirectoryExists(dirname($path));
                File::put($path, $content);
            }

            return new AiResponseDTO(
                content: 'done', parsedOutput: null, usage: new AiUsageDTO(0, 0, 0),
                provider: 'claude-code-vps', model: '', latencyMs: 1,
            );
        });
        $this->app->instance(LocalAgentGateway::class, $gw);
    }

    /** Fake the git client so createPullRequest returns a PR and records the draft flag. */
    private function fakePrClient(array &$captured): void
    {
        $client = Mockery::mock(GitClientInterface::class);
        $client->shouldReceive('createPullRequest')
            ->andReturnUsing(function ($title, $body, $head, $base, $draft = false) use (&$captured) {
                $captured = compact('title', 'body', 'head', 'base', 'draft');

                return ['pr_number' => '7', 'pr_url' => 'https://example.test/pr/7', 'title' => $title, 'status' => 'open'];
            });

        $router = Mockery::mock(GitOperationRouter::class);
        $router->shouldReceive('resolve')->andReturn($client);
        $this->app->instance(GitOperationRouter::class, $router);
    }

    public function test_happy_path_opens_draft_pr_and_awaits_approval(): void
    {
        $repo = $this->repo();
        $exp = $this->experiment(['git_repository_id' => $repo->id]);
        $this->fakeAgentWriting('fix.txt');
        $captured = [];
        $this->fakePrClient($captured);

        app(ExecuteWarmDebugBuildAction::class)->execute($exp);

        $exp->refresh();
        $this->assertSame(ExperimentStatus::AwaitingApproval, $exp->status);
        $this->assertTrue($captured['draft'], 'PR must be opened as a draft');
        $this->assertSame('main', $captured['base']);

        $stage = ExperimentStage::where('experiment_id', $exp->id)->where('stage', StageType::Building)->first();
        $this->assertSame(StageStatus::Completed, $stage->status);
        $this->assertContains('https://example.test/pr/7', $stage->output_snapshot['pr_urls']);

        // The fix branch was actually pushed to the bare origin.
        $branches = Process::run(['git', '-C', $this->bare, 'branch', '--list', 'fleetq/fix-*'])->output();
        $this->assertStringContainsString('fleetq/fix-', $branches);
    }

    public function test_no_changes_fails_the_build(): void
    {
        $repo = $this->repo();
        $exp = $this->experiment(['git_repository_id' => $repo->id]);
        $this->fakeAgentWriting(null); // agent touches nothing
        $captured = [];
        $this->fakePrClient($captured);

        app(ExecuteWarmDebugBuildAction::class)->execute($exp);

        $exp->refresh();
        $this->assertSame(ExperimentStatus::BuildingFailed, $exp->status);
        $this->assertSame([], $captured, 'no PR should be opened when there are no changes');
    }

    public function test_missing_repository_fails_the_build(): void
    {
        $exp = $this->experiment([]); // no git_repository_id, no agent
        $this->fakeAgentWriting('fix.txt');

        app(ExecuteWarmDebugBuildAction::class)->execute($exp);

        $exp->refresh();
        $this->assertSame(ExperimentStatus::BuildingFailed, $exp->status);
    }

    public function test_falls_back_to_default_repo_when_no_constraint(): void
    {
        // Retry of an experiment delegated before a repo was configured: no
        // git_repository_id in constraints. It must resolve the team default repo.
        GitRepository::create([
            'team_id' => $this->team->id, 'name' => 'other',
            'url' => 'https://example.test/other.git', 'default_branch' => 'main',
        ]);
        GitRepository::create([
            'team_id' => $this->team->id, 'name' => 'r',
            'url' => $this->bare, 'default_branch' => 'main', 'is_default' => true,
        ]);

        $exp = $this->experiment([]); // no git_repository_id
        $this->fakeAgentWriting('fix.txt');
        $captured = [];
        $this->fakePrClient($captured);

        app(ExecuteWarmDebugBuildAction::class)->execute($exp);

        $exp->refresh();
        $this->assertSame(ExperimentStatus::AwaitingApproval, $exp->status);
        $this->assertTrue($captured['draft']);
    }

    public function test_falls_back_to_single_repo_when_no_constraint(): void
    {
        $this->repo(); // single team repo → the bare remote
        $exp = $this->experiment([]); // no git_repository_id
        $this->fakeAgentWriting('fix.txt');
        $captured = [];
        $this->fakePrClient($captured);

        app(ExecuteWarmDebugBuildAction::class)->execute($exp);

        $exp->refresh();
        $this->assertSame(ExperimentStatus::AwaitingApproval, $exp->status);
    }

    public function test_best_of_two_selects_smaller_within_roots_candidate(): void
    {
        config(['experiments.warm_build.candidates' => 2]);
        $repo = $this->repo();
        $exp = $this->experiment(['git_repository_id' => $repo->id]);
        // candidate 1: two files; candidate 2: one file (smaller) — both within roots.
        $this->fakeAgentPerCall([
            ['app/a.php' => 'x', 'app/b.php' => 'y'],
            ['app/a.php' => 'z'],
        ]);
        $captured = [];
        $this->fakePrClient($captured);

        app(ExecuteWarmDebugBuildAction::class)->execute($exp);

        $exp->refresh();
        $this->assertSame(ExperimentStatus::AwaitingApproval, $exp->status);
        $this->assertTrue($captured['draft']);
        $this->assertStringContainsString('Selected candidate 2 of 2', $captured['body']);
        $this->assertStringContainsString('within writable-roots', $captured['body']);

        $stage = ExperimentStage::where('experiment_id', $exp->id)->where('stage', StageType::Building)->first();
        $this->assertStringContainsString('best of 2', (string) $stage->output_snapshot['summary']);
    }

    public function test_best_of_two_discards_migration_violating_candidate(): void
    {
        config(['experiments.warm_build.candidates' => 2]);
        $repo = $this->repo();
        $exp = $this->experiment(['git_repository_id' => $repo->id]);
        // candidate 1 rewrites a migration (out of roots); candidate 2 edits app/.
        $this->fakeAgentPerCall([
            ['database/migrations/2026_07_08_000000_x.php' => 'evil'],
            ['app/fix.php' => 'good'],
        ]);
        $captured = [];
        $this->fakePrClient($captured);

        app(ExecuteWarmDebugBuildAction::class)->execute($exp);

        $exp->refresh();
        $this->assertSame(ExperimentStatus::AwaitingApproval, $exp->status);
        $this->assertStringContainsString('Selected candidate 2 of 2', $captured['body']);
        // The winning branch must contain the app fix, not the migration edit.
        $show = Process::run(['git', '-C', $this->bare, 'show', '--stat', 'fleetq/fix-'.substr($exp->id, 0, 8)])->output();
        $this->assertStringContainsString('app/fix.php', $show);
        $this->assertStringNotContainsString('database/migrations', $show);
    }

    public function test_one_candidate_crash_does_not_sink_the_build(): void
    {
        config(['experiments.warm_build.candidates' => 2]);
        $repo = $this->repo();
        $exp = $this->experiment(['git_repository_id' => $repo->id]);
        // candidate 1 crashes; candidate 2 succeeds.
        $this->fakeAgentPerCall([[], ['app/fix.php' => 'good']], throwOn: 0);
        $captured = [];
        $this->fakePrClient($captured);

        app(ExecuteWarmDebugBuildAction::class)->execute($exp);

        $exp->refresh();
        $this->assertSame(ExperimentStatus::AwaitingApproval, $exp->status);
        $this->assertTrue($captured['draft']);
    }

    public function test_all_candidates_violate_roots_still_opens_flagged_pr(): void
    {
        config(['experiments.warm_build.candidates' => 2]);
        $repo = $this->repo();
        $exp = $this->experiment(['git_repository_id' => $repo->id]);
        // Both candidates only touch migrations: pick returns the best-scoring one
        // but flags the violation for the human reviewer (design: don't hard-fail).
        $this->fakeAgentPerCall([
            ['database/migrations/a.php' => 'x'],
            ['database/migrations/b.php' => 'y'],
        ]);
        $captured = [];
        $this->fakePrClient($captured);

        app(ExecuteWarmDebugBuildAction::class)->execute($exp);

        $exp->refresh();
        $this->assertSame(ExperimentStatus::AwaitingApproval, $exp->status);
        $this->assertStringContainsString('writable-roots violation', $captured['body']);
    }

    public function test_default_candidates_is_one_run(): void
    {
        // No candidates config → exactly one agent invocation (parity with legacy).
        $repo = $this->repo();
        $exp = $this->experiment(['git_repository_id' => $repo->id]);
        $calls = 0;
        $gw = Mockery::mock(LocalAgentGateway::class);
        $gw->shouldReceive('complete')->andReturnUsing(function ($request) use (&$calls) {
            $calls++;
            File::put($request->workingDirectory.'/fix.txt', 'patched');

            return new AiResponseDTO(content: 'done', parsedOutput: null, usage: new AiUsageDTO(0, 0, 0), provider: 'claude-code-vps', model: '', latencyMs: 1);
        });
        $this->app->instance(LocalAgentGateway::class, $gw);
        $captured = [];
        $this->fakePrClient($captured);

        app(ExecuteWarmDebugBuildAction::class)->execute($exp);

        $this->assertSame(1, $calls, 'default config must run exactly one candidate');
        // N=1 PR body must NOT contain best-of-N framing.
        $this->assertStringNotContainsString('Selected candidate', $captured['body']);
    }

    public function test_transient_capacity_rethrows_and_leaves_run_building(): void
    {
        $repo = $this->repo();
        $exp = $this->experiment(['git_repository_id' => $repo->id]);

        // The VPS slot is acquired before any agent work: a cap failure means
        // nothing was spent. The action must surface it (retryable) so the job
        // can re-dispatch — NOT flip the run to BuildingFailed.
        $gw = Mockery::mock(LocalAgentGateway::class);
        $gw->shouldReceive('complete')
            ->andThrow(VpsLocalAgentException::concurrencyCapReached(2));
        $this->app->instance(LocalAgentGateway::class, $gw);

        try {
            app(ExecuteWarmDebugBuildAction::class)->execute($exp);
            $this->fail('expected the transient cap exception to propagate');
        } catch (VpsLocalAgentException $e) {
            $this->assertTrue($e->retryable);
        }

        $exp->refresh();
        $this->assertSame(ExperimentStatus::Building, $exp->status);
    }
}
