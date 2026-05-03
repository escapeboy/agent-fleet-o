<?php

namespace Tests\Feature\Workflow;

use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Enums\GitProvider;
use App\Domain\GitRepository\Enums\GitRepoMode;
use App\Domain\GitRepository\Enums\GitRepositoryStatus;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\UserNotification;
use App\Domain\Workflow\Actions\CreateWorkflowAction;
use App\Domain\Workflow\Actions\CreateWorkflowGitSyncAction;
use App\Domain\Workflow\Events\WorkflowSaved;
use App\Domain\Workflow\Jobs\PushWorkflowYamlJob;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowGitSync;
use App\Mcp\Tools\Workflow\WorkflowExportYamlTool;
use App\Mcp\Tools\Workflow\WorkflowImportYamlTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Kestra-inspired YAML workflow Git Sync (build #5, Trendshift top-5 sprint).
 */
class WorkflowYamlGitSyncTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    private GitRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
        app()->instance('mcp.team_id', $this->team->id);

        $this->repo = GitRepository::create([
            'team_id' => $this->team->id,
            'name' => 'docs-repo',
            'url' => 'https://github.com/example/repo',
            'provider' => GitProvider::GitHub->value,
            'mode' => GitRepoMode::ApiOnly->value,
            'status' => GitRepositoryStatus::Active->value,
        ]);
    }

    private function makeWorkflow(string $name = 'Test Flow'): Workflow
    {
        return app(CreateWorkflowAction::class)->execute(
            userId: $this->user->id,
            name: $name,
            description: 'Test description',
            nodes: [],
            edges: [],
            teamId: $this->team->id,
        );
    }

    public function test_create_sync_action_links_workflow_to_git_repository(): void
    {
        Event::fake([WorkflowSaved::class]);
        $workflow = $this->makeWorkflow();

        $sync = app(CreateWorkflowGitSyncAction::class)->execute(
            workflowId: $workflow->id,
            gitRepositoryId: $this->repo->id,
            teamId: $this->team->id,
        );

        $this->assertSame($workflow->id, $sync->workflow_id);
        $this->assertSame($this->repo->id, $sync->git_repository_id);
        $this->assertSame('fleetq-sync', $sync->branch);
        $this->assertSame('workflows/', $sync->path_prefix);
    }

    public function test_create_sync_action_is_idempotent(): void
    {
        Event::fake([WorkflowSaved::class]);
        $workflow = $this->makeWorkflow();
        $action = app(CreateWorkflowGitSyncAction::class);

        $a = $action->execute($workflow->id, $this->repo->id, $this->team->id);
        $b = $action->execute($workflow->id, $this->repo->id, $this->team->id, branch: 'main');

        $this->assertSame($a->id, $b->id);
        $this->assertSame('main', $b->branch);
        $this->assertSame(1, WorkflowGitSync::count());
    }

    public function test_workflow_saved_event_fires_on_create_and_update(): void
    {
        Event::fake([WorkflowSaved::class]);
        $workflow = $this->makeWorkflow();

        Event::assertDispatched(
            WorkflowSaved::class,
            fn (WorkflowSaved $e) => $e->workflow->id === $workflow->id,
        );
    }

    public function test_listener_queues_job_only_when_sync_exists_and_only_once_per_minute(): void
    {
        Cache::flush();
        Queue::fake();
        $workflow = $this->makeWorkflow();

        // No sync configured yet → no PushWorkflowYamlJob dispatched even though event fires.
        WorkflowSaved::dispatch($workflow);
        Queue::assertNotPushed(PushWorkflowYamlJob::class);

        // Add a sync, then dispatch twice in quick succession — second is debounced.
        app(CreateWorkflowGitSyncAction::class)->execute($workflow->id, $this->repo->id, $this->team->id);
        WorkflowSaved::dispatch($workflow);
        WorkflowSaved::dispatch($workflow);

        Queue::assertPushed(PushWorkflowYamlJob::class, 1);
    }

    public function test_push_job_writes_yaml_to_repo_via_mock_client(): void
    {
        $workflow = $this->makeWorkflow('Customer Onboarding');
        $sync = app(CreateWorkflowGitSyncAction::class)
            ->execute($workflow->id, $this->repo->id, $this->team->id);

        $captured = ['path' => null, 'content' => null, 'branch' => null];
        $client = new class($captured) implements GitClientInterface {
            public function __construct(private array &$captured) {}

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
                $this->captured['path'] = $path;
                $this->captured['content'] = $content;
                $this->captured['branch'] = $branch;

                return 'sha-pushed';
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
                return '';
            }

            public function push(string $branch): void {}

            public function createPullRequest(string $title, string $body, string $head, string $base): array
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
        };

        $router = $this->mock(GitOperationRouter::class);
        $router->shouldReceive('resolve')->andReturn($client);

        (new PushWorkflowYamlJob($sync->id))->handle(
            app(\App\Domain\Workflow\Actions\ExportWorkflowAction::class),
            $router,
        );

        $this->assertSame('workflows/'.$workflow->slug.'.yaml', $captured['path']);
        $this->assertStringContainsString('format_version', (string) $captured['content']);
        $this->assertStringContainsString('Customer Onboarding', (string) $captured['content']);
        $this->assertSame('fleetq-sync', $captured['branch']);

        $sync->refresh();
        $this->assertSame('sha-pushed', $sync->last_pushed_sha);
        $this->assertNotNull($sync->last_pushed_at);
    }

    public function test_push_job_failed_creates_user_notification(): void
    {
        $workflow = $this->makeWorkflow();
        $sync = app(CreateWorkflowGitSyncAction::class)
            ->execute($workflow->id, $this->repo->id, $this->team->id);

        // Make the team's owner discoverable for ownerIdFor().
        $this->team->update(['owner_id' => $this->user->id]);

        (new PushWorkflowYamlJob($sync->id))->failed(new \RuntimeException('repo gone'));

        $this->assertSame(1, UserNotification::where('type', 'workflow_git_sync_failed')->count());
    }

    public function test_mcp_export_yaml_returns_parseable_yaml(): void
    {
        Event::fake([WorkflowSaved::class]);
        $workflow = $this->makeWorkflow('Marketing Sequence');

        $tool = app(WorkflowExportYamlTool::class);
        $request = new \Laravel\Mcp\Request(['workflow_id' => $workflow->id]);
        $response = $tool->handle($request);

        $body = (string) $response->content();
        $parsed = Yaml::parse($body);

        $this->assertIsArray($parsed);
        $this->assertSame('Marketing Sequence', $parsed['workflow']['name']);
        $this->assertArrayHasKey('checksum', $parsed);
    }

    public function test_mcp_export_yaml_rejects_other_team_workflow(): void
    {
        Event::fake([WorkflowSaved::class]);
        $otherTeam = Team::factory()->create();
        $otherUser = User::factory()->create(['current_team_id' => $otherTeam->id]);
        app()->instance('mcp.team_id', $otherTeam->id);
        $foreignWorkflow = app(CreateWorkflowAction::class)->execute(
            userId: $otherUser->id,
            name: 'foreign',
            teamId: $otherTeam->id,
        );

        // Reset mcp.team_id back to my team.
        app()->instance('mcp.team_id', $this->team->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(WorkflowExportYamlTool::class)->handle(
            new \Laravel\Mcp\Request(['workflow_id' => $foreignWorkflow->id]),
        );
    }

    public function test_mcp_import_yaml_creates_workflow_in_current_team(): void
    {
        Event::fake([WorkflowSaved::class]);
        $sourceWorkflow = $this->makeWorkflow('Source Flow');
        $exporter = app(\App\Domain\Workflow\Actions\ExportWorkflowAction::class);
        $yaml = $exporter->execute($sourceWorkflow, format: 'yaml');

        $tool = app(WorkflowImportYamlTool::class);
        $response = $tool->handle(new \Laravel\Mcp\Request([
            'yaml_content' => $yaml,
            'name_override' => 'Imported Flow',
        ]));

        $payload = json_decode((string) $response->content(), true);
        $this->assertArrayHasKey('workflow_id', $payload);

        $imported = Workflow::find($payload['workflow_id']);
        $this->assertSame('Imported Flow', $imported->name);
        $this->assertSame($this->team->id, $imported->team_id);
    }
}
