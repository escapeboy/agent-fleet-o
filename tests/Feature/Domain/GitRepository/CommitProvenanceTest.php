<?php

namespace Tests\Feature\Domain\GitRepository;

use App\Domain\Agent\Services\WorktreeManager;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Approval\Services\ActionProposalExecutor;
use App\Domain\GitRepository\Actions\GenerateCommitMessageAction;
use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Enums\CommitDiscipline;
use App\Domain\GitRepository\Exceptions\GitOperationProposedException;
use App\Domain\GitRepository\Models\GitCommitProvenance;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\GitRepository\Services\GitProvenanceContext;
use App\Domain\GitRepository\Services\GitProvenanceRecorder;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\Git\Clients\GitHubApiClient;
use App\Infrastructure\Git\Clients\ProvenanceGitClient;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommitProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private GitRepository $repo;

    private ProvenanceFakeGitClient $inner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        $this->repo = GitRepository::create([
            'team_id' => $this->team->id,
            'name' => 'test-repo',
            'url' => 'https://github.com/example/test',
            'provider' => 'github',
            'mode' => 'api_only',
            'default_branch' => 'main',
            'config' => [],
            'status' => 'active',
        ]);

        $this->inner = new ProvenanceFakeGitClient;
    }

    private function decorator(): ProvenanceGitClient
    {
        return new ProvenanceGitClient(
            $this->inner,
            $this->repo,
            app(GitProvenanceContext::class),
            app(GitProvenanceRecorder::class),
        );
    }

    private function enable(bool $on = true): void
    {
        config(['git_repository.provenance.enabled' => $on]);
    }

    public function test_flag_off_is_pass_through(): void
    {
        $this->enable(false);

        app(GitProvenanceContext::class)->with(['experiment_id' => 'e1', 'agent_id' => 'a1'], function () {
            $this->decorator()->commit([['path' => 'a', 'content' => 'x']], 'fix: a', 'main');
            $this->decorator()->writeFile('a', 'x', 'fix: b', 'main');
        });

        $this->assertSame('fix: a', $this->inner->messages[0]);
        $this->assertSame('fix: b', $this->inner->messages[1]);
        $this->assertSame(0, GitCommitProvenance::query()->withoutGlobalScopes()->count());
    }

    public function test_flag_on_with_context_adds_trailers_and_records_row(): void
    {
        $this->enable();
        $exp = (string) Str::uuid();
        $agent = (string) Str::uuid();

        $sha = app(GitProvenanceContext::class)->with(
            ['experiment_id' => $exp, 'agent_id' => $agent, 'source' => 'agent_tool'],
            fn () => $this->decorator()->commit([['path' => 'a', 'content' => 'x']], 'fix: a', 'feature'),
        );

        $this->assertSame('sha-commit', $sha);
        $this->assertSame("fix: a\n\nFleetQ-Experiment: {$exp}\nFleetQ-Agent: {$agent}", $this->inner->messages[0]);

        $row = GitCommitProvenance::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('sha-commit', $row->commit_sha);
        $this->assertSame($this->repo->id, $row->git_repository_id);
        $this->assertSame($this->team->id, $row->team_id);
        $this->assertSame('feature', $row->branch);
        $this->assertSame('agent_tool', $row->source);
        $this->assertSame($exp, $row->experiment_id);
        $this->assertSame($agent, $row->agent_id);
        $this->assertSame(['FleetQ-Experiment' => $exp, 'FleetQ-Agent' => $agent], $row->trailers);
    }

    public function test_flag_on_without_ids_records_source_only(): void
    {
        $this->enable();

        app(GitProvenanceContext::class)->with(['source' => 'mcp_tool'], function () {
            $this->decorator()->writeFile('a', 'x', 'fix: a', 'main');
        });

        $this->assertSame('fix: a', $this->inner->messages[0]);
        $row = GitCommitProvenance::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('sha-write', $row->commit_sha);
        $this->assertSame('mcp_tool', $row->source);
        $this->assertNull($row->experiment_id);
        $this->assertNull($row->trailers);
    }

    public function test_recording_failure_does_not_break_commit_and_is_reported(): void
    {
        $this->enable();
        Schema::drop('git_commit_provenance');

        $reported = [];
        $this->app->make(ExceptionHandler::class);
        $handler = new class($this->app->make(ExceptionHandler::class), $reported) extends Handler
        {
            public function __construct(private $real, public array &$seen)
            {
                parent::__construct(app());
            }

            public function report($e)
            {
                $this->seen[] = $e;
            }
        };
        $this->app->instance(ExceptionHandler::class, $handler);

        $sha = $this->decorator()->commit([['path' => 'a', 'content' => 'x']], 'fix: a', 'main');

        $this->assertSame('sha-commit', $sha);
        $this->assertNotEmpty($handler->seen);
    }

    public function test_router_puts_provenance_inside_atomic_so_trailers_survive(): void
    {
        $this->enable();
        $this->repo->update(['commit_discipline' => CommitDiscipline::Atomic->value]);
        $this->app->bind(GitHubApiClient::class, fn () => $this->inner);
        $this->mock(GenerateCommitMessageAction::class, function ($m) {
            $m->shouldReceive('execute')->andReturn('feat: rewritten single line');
        });

        $client = app(GitOperationRouter::class)->resolve($this->repo->fresh());
        app(GitProvenanceContext::class)->with(
            ['experiment_id' => 'exp-1', 'agent_id' => 'ag-1', 'source' => 'agent_tool'],
            fn () => $client->commit([['path' => 'a', 'content' => 'x']], 'WIP', 'main'),
        );

        $this->assertSame("feat: rewritten single line\n\nFleetQ-Experiment: exp-1\nFleetQ-Agent: ag-1", $this->inner->messages[0]);
    }

    public function test_router_without_flag_does_not_wrap(): void
    {
        $this->enable(false);
        $this->app->bind(GitHubApiClient::class, fn () => $this->inner);

        $client = app(GitOperationRouter::class)->resolve($this->repo->fresh());
        app(GitProvenanceContext::class)->with(['experiment_id' => 'exp-1'], fn () => $client->commit([], 'm', 'main'));

        $this->assertSame('m', $this->inner->messages[0]);
        $this->assertSame(0, GitCommitProvenance::query()->withoutGlobalScopes()->count());
    }

    public function test_worktree_manager_commit_adds_trailer_and_records_row(): void
    {
        $this->enable();
        $dir = sys_get_temp_dir().'/prov-wt-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $run = fn (array $cmd) => Process::path($dir)->run($cmd)->output();
            $run(['git', 'init', '-q', '-b', 'main']);
            $run(['git', 'config', 'user.email', 't@t']);
            $run(['git', 'config', 'user.name', 'T']);
            file_put_contents($dir.'/f.txt', 'hello');

            $exp = (string) Str::uuid();
            $sha = app(GitProvenanceContext::class)->with(
                ['team_id' => $this->team->id, 'experiment_id' => $exp, 'source' => 'worktree_skill'],
                fn () => app(WorktreeManager::class)->commit($dir, 'agent: skill'),
            );

            $body = (string) $run(['git', 'log', '-1', '--format=%B']);
            $this->assertStringContainsString("agent: skill\n\nFleetQ-Experiment: {$exp}", $body);

            $row = GitCommitProvenance::query()->withoutGlobalScopes()->firstOrFail();
            $this->assertSame($sha, $row->commit_sha);
            $this->assertSame($this->team->id, $row->team_id);
            $this->assertNull($row->git_repository_id);
            $this->assertSame('worktree_skill', $row->source);
        } finally {
            Process::run(['rm', '-rf', $dir]);
        }
    }

    public function test_worktree_manager_flag_off_leaves_message_untouched(): void
    {
        $this->enable(false);
        $dir = sys_get_temp_dir().'/prov-wt-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $run = fn (array $cmd) => Process::path($dir)->run($cmd)->output();
            $run(['git', 'init', '-q', '-b', 'main']);
            $run(['git', 'config', 'user.email', 't@t']);
            $run(['git', 'config', 'user.name', 'T']);
            file_put_contents($dir.'/f.txt', 'hello');

            app(GitProvenanceContext::class)->with(
                ['team_id' => $this->team->id, 'experiment_id' => 'e'],
                fn () => app(WorktreeManager::class)->commit($dir, 'agent: skill'),
            );

            $this->assertSame('agent: skill', trim((string) $run(['git', 'log', '-1', '--format=%B'])));
            $this->assertSame(0, GitCommitProvenance::query()->withoutGlobalScopes()->count());
        } finally {
            Process::run(['rm', '-rf', $dir]);
        }
    }

    public function test_gated_proposal_carries_context_and_replay_records_approval_replay(): void
    {
        $this->enable();
        $this->team->update(['settings' => ['action_proposal_policy' => ['low' => 'auto', 'medium' => 'ask', 'high' => 'ask']]]);
        $this->app->bind(GitHubApiClient::class, fn () => $this->inner);

        $exp = (string) Str::uuid();
        $agent = (string) Str::uuid();
        $client = app(GitOperationRouter::class)->resolve($this->repo->fresh());

        $proposalId = null;
        try {
            app(GitProvenanceContext::class)->with(
                ['experiment_id' => $exp, 'agent_id' => $agent, 'source' => 'agent_tool'],
                fn () => $client->commit([['path' => 'a', 'content' => 'x']], 'fix: a', 'main'),
            );
            $this->fail('expected proposal');
        } catch (GitOperationProposedException $e) {
            $proposalId = $e->proposalId;
        }

        $proposal = ActionProposal::findOrFail($proposalId);
        $this->assertSame('agent_tool', $proposal->payload['provenance']['source']);
        $this->assertSame($exp, $proposal->payload['provenance']['experiment_id']);
        $this->assertSame($agent, $proposal->payload['provenance']['agent_id']);
        $this->assertSame([], $this->inner->messages);

        // Replay happens later, outside the original context.
        app(ActionProposalExecutor::class)->execute($proposal, $this->user);

        $this->assertSame("fix: a\n\nFleetQ-Experiment: {$exp}\nFleetQ-Agent: {$agent}", $this->inner->messages[0]);
        $row = GitCommitProvenance::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('approval_replay', $row->source);
        $this->assertSame($exp, $row->experiment_id);
        $this->assertSame($agent, $row->agent_id);
    }

    public function test_gate_payload_has_no_provenance_when_flag_off(): void
    {
        $this->enable(false);
        $this->team->update(['settings' => ['action_proposal_policy' => ['low' => 'auto', 'medium' => 'ask', 'high' => 'ask']]]);
        $this->app->bind(GitHubApiClient::class, fn () => $this->inner);
        $client = app(GitOperationRouter::class)->resolve($this->repo->fresh());

        try {
            $client->commit([], 'm', 'main');
            $this->fail('expected proposal');
        } catch (GitOperationProposedException $e) {
            $this->assertArrayNotHasKey('provenance', ActionProposal::findOrFail($e->proposalId)->payload);
        }
    }

    public function test_migration_runs_up_and_down(): void
    {
        $this->assertTrue(Schema::hasTable('git_commit_provenance'));
        $this->assertTrue(Schema::hasColumns('git_commit_provenance', [
            'id', 'team_id', 'git_repository_id', 'commit_sha', 'branch', 'source',
            'experiment_id', 'agent_id', 'skill_execution_id', 'trailers', 'created_at', 'updated_at',
        ]));

        $migration = require base_path('database/migrations/2026_10_07_000001_create_git_commit_provenance_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('git_commit_provenance'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('git_commit_provenance'));
    }
}

class ProvenanceFakeGitClient implements GitClientInterface
{
    /** @var list<string> */
    public array $messages = [];

    public function ping(): bool
    {
        return true;
    }

    public function readFile(string $path, string $ref = 'HEAD'): string
    {
        return '';
    }

    public function writeFile(string $path, string $content, string $message, string $branch): string
    {
        $this->messages[] = $message;

        return 'sha-write';
    }

    public function listFiles(string $path = '/', string $ref = 'HEAD'): array
    {
        return [];
    }

    public function getFileTree(string $ref = 'HEAD'): array
    {
        return [];
    }

    public function createBranch(string $branch, string $from): void {}

    public function commit(array $changes, string $message, string $branch): string
    {
        $this->messages[] = $message;

        return 'sha-commit';
    }

    public function push(string $branch): void {}

    public function createPullRequest(string $title, string $body, string $head, string $base, bool $draft = false): array
    {
        return [];
    }

    public function listPullRequests(string $state = 'open'): array
    {
        return [];
    }

    public function mergePullRequest(int $prNumber, string $method = 'squash', ?string $commitTitle = null, ?string $commitMessage = null): array
    {
        return [];
    }

    public function getPullRequestStatus(int $prNumber): array
    {
        return [];
    }

    public function dispatchWorkflow(string $workflowId, string $ref = 'main', array $inputs = []): array
    {
        return [];
    }

    public function createRelease(string $tagName, string $name, string $body, string $targetCommitish = 'main', bool $draft = false, bool $prerelease = false): array
    {
        return [];
    }

    public function closePullRequest(int $prNumber): void {}

    public function getCommitLog(?string $fromRef = null, string $toRef = 'HEAD', int $limit = 100): array
    {
        return [];
    }
}
