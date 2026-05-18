<?php

namespace Tests\Feature\Livewire;

use App\Domain\GitRepository\Jobs\PushContextToGitJob;
use App\Domain\GitRepository\Models\ContextGitSync;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Shared\Models\Team;
use App\Livewire\Settings\GitSyncPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class GitSyncPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $this->user->id]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function repo(): GitRepository
    {
        return GitRepository::create([
            'team_id' => $this->team->id,
            'name' => 'context-repo',
            'url' => 'https://github.com/example/context',
            'provider' => 'github',
            'mode' => 'api_only',
            'default_branch' => 'main',
            'config' => [],
            'status' => 'active',
        ]);
    }

    public function test_saves_context_git_sync(): void
    {
        $repo = $this->repo();

        Livewire::test(GitSyncPage::class)
            ->set('selectedRepoId', $repo->id)
            ->set('branch', 'fleetq-context')
            ->set('syncMemory', false)
            ->call('saveContextSync')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('context_git_syncs', [
            'team_id' => $this->team->id,
            'git_repository_id' => $repo->id,
            'sync_memory' => false,
        ]);
    }

    public function test_export_now_queues_a_push(): void
    {
        Queue::fake();
        $repo = $this->repo();
        ContextGitSync::create([
            'team_id' => $this->team->id,
            'git_repository_id' => $repo->id,
            'branch' => 'fleetq-context',
            'sync_artifacts' => true,
            'sync_memory' => true,
            'artifact_path_prefix' => 'artifacts/',
            'memory_path_prefix' => 'memory/',
        ]);

        Livewire::test(GitSyncPage::class)->call('exportNow');

        Queue::assertPushed(PushContextToGitJob::class);
    }

    public function test_save_requires_a_repository(): void
    {
        Livewire::test(GitSyncPage::class)
            ->set('selectedRepoId', '')
            ->set('branch', 'fleetq-context')
            ->call('saveContextSync')
            ->assertHasErrors('selectedRepoId');
    }
}
