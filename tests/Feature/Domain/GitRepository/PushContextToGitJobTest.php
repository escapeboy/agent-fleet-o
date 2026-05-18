<?php

namespace Tests\Feature\Domain\GitRepository;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Jobs\PushContextToGitJob;
use App\Domain\GitRepository\Models\ContextGitSync;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\ContextMarkdownRenderer;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\Shared\Models\Team;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PushContextToGitJobTest extends TestCase
{
    use RefreshDatabase;

    private function repo(Team $team): GitRepository
    {
        return GitRepository::create([
            'team_id' => $team->id,
            'name' => 'context-repo',
            'url' => 'https://github.com/example/context',
            'provider' => 'github',
            'mode' => 'api_only',
            'default_branch' => 'main',
            'config' => [],
            'status' => 'active',
        ]);
    }

    private function sync(Team $team, GitRepository $repo, bool $syncMemory = false): ContextGitSync
    {
        return ContextGitSync::create([
            'team_id' => $team->id,
            'git_repository_id' => $repo->id,
            'branch' => 'fleetq-context',
            'sync_artifacts' => true,
            'sync_memory' => $syncMemory,
            'artifact_path_prefix' => 'artifacts/',
            'memory_path_prefix' => 'memory/',
        ]);
    }

    public function test_commits_artifact_files_and_records_the_push(): void
    {
        // Prevent the ArtifactVersion::created hook from auto-running a real push.
        Queue::fake();

        $team = Team::factory()->create();
        $repo = $this->repo($team);
        $sync = $this->sync($team, $repo);

        $experiment = Experiment::factory()->create(['team_id' => $team->id]);
        $artifact = Artifact::create([
            'team_id' => $team->id,
            'experiment_id' => $experiment->id,
            'type' => 'doc',
            'name' => 'Spec',
            'current_version' => 1,
        ]);
        ArtifactVersion::create([
            'team_id' => $team->id,
            'artifact_id' => $artifact->id,
            'version' => 1,
            'content' => 'Spec body',
        ]);

        $client = Mockery::mock(GitClientInterface::class);
        $client->shouldReceive('commit')->once()->andReturn('sha-abc123');
        $router = Mockery::mock(GitOperationRouter::class);
        $router->shouldReceive('resolve')->once()->andReturn($client);

        (new PushContextToGitJob($sync->id))->handle($router, new ContextMarkdownRenderer);

        $sync->refresh();
        $this->assertSame('sha-abc123', $sync->last_pushed_sha);
        $this->assertNotNull($sync->last_pushed_at);
    }

    public function test_missing_sync_row_returns_without_touching_git(): void
    {
        $router = Mockery::mock(GitOperationRouter::class);
        $router->shouldNotReceive('resolve');

        (new PushContextToGitJob('019e0000-0000-7000-8000-000000000000'))
            ->handle($router, new ContextMarkdownRenderer);

        $this->assertTrue(true);
    }

    public function test_failed_writes_a_user_notification(): void
    {
        $team = Team::factory()->create();
        $repo = $this->repo($team);
        $sync = $this->sync($team, $repo);

        (new PushContextToGitJob($sync->id))->failed(new RuntimeException('boom'));

        $this->assertDatabaseHas('user_notifications', [
            'team_id' => $team->id,
            'type' => 'context_git_sync_failed',
        ]);
    }
}
