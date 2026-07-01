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

    /** Fake the git client so createPullRequest returns a PR and records the draft flag. */
    private function fakePrClient(array &$captured): void
    {
        $client = Mockery::mock(GitClientInterface::class);
        $client->shouldReceive('createPullRequest')
            ->andReturnUsing(function ($title, $body, $head, $base, $draft = false) use (&$captured) {
                $captured = compact('title', 'head', 'base', 'draft');

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
}
