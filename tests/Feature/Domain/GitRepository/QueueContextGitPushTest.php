<?php

namespace Tests\Feature\Domain\GitRepository;

use App\Domain\GitRepository\Jobs\PushContextToGitJob;
use App\Domain\GitRepository\Listeners\QueueContextGitPush;
use App\Domain\GitRepository\Models\ContextGitSync;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueContextGitPushTest extends TestCase
{
    use RefreshDatabase;

    private function syncedTeam(): Team
    {
        $team = Team::factory()->create();
        $repo = GitRepository::create([
            'team_id' => $team->id,
            'name' => 'context-repo',
            'url' => 'https://github.com/example/context',
            'provider' => 'github',
            'mode' => 'api_only',
            'default_branch' => 'main',
            'config' => [],
            'status' => 'active',
        ]);
        ContextGitSync::create([
            'team_id' => $team->id,
            'git_repository_id' => $repo->id,
            'branch' => 'fleetq-context',
            'sync_artifacts' => true,
            'sync_memory' => true,
            'artifact_path_prefix' => 'artifacts/',
            'memory_path_prefix' => 'memory/',
        ]);

        return $team;
    }

    public function test_dispatches_once_and_debounces_repeated_calls(): void
    {
        Queue::fake();
        $team = $this->syncedTeam();

        $listener = new QueueContextGitPush;
        $listener->handle($team->id);
        $listener->handle($team->id);
        $listener->handle($team->id);

        Queue::assertPushed(PushContextToGitJob::class, 1);
    }

    public function test_no_op_when_team_has_no_sync(): void
    {
        Queue::fake();
        $team = Team::factory()->create();

        (new QueueContextGitPush)->handle($team->id);

        Queue::assertNotPushed(PushContextToGitJob::class);
    }
}
