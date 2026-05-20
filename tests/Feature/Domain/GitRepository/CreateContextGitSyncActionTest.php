<?php

namespace Tests\Feature\Domain\GitRepository;

use App\Domain\GitRepository\Actions\CreateContextGitSyncAction;
use App\Domain\GitRepository\Models\ContextGitSync;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateContextGitSyncActionTest extends TestCase
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

    public function test_creates_then_updates_idempotently(): void
    {
        $team = Team::factory()->create();
        $repo = $this->repo($team);
        $action = new CreateContextGitSyncAction;

        $first = $action->execute($team->id, $repo->id);
        $second = $action->execute($team->id, $repo->id, branch: 'custom', syncMemory: false);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('custom', $second->branch);
        $this->assertFalse($second->sync_memory);
        $this->assertSame(1, ContextGitSync::where('team_id', $team->id)->count());
    }

    public function test_rejects_a_repository_from_another_team(): void
    {
        $team = Team::factory()->create();
        $otherRepo = $this->repo(Team::factory()->create());

        $this->expectException(ModelNotFoundException::class);

        (new CreateContextGitSyncAction)->execute($team->id, $otherRepo->id);
    }
}
