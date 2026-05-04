<?php

namespace Tests\Feature\Livewire\GitRepositories;

use App\Domain\GitRepository\Enums\TestRatchetMode;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Shared\Models\Team;
use App\Livewire\GitRepositories\GitRepositoryDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TestRatchetModeUiTest extends TestCase
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

    public function test_mount_defaults_to_soft_when_unset(): void
    {
        $repo = $this->makeRepo(['config' => []]);

        Livewire::test(GitRepositoryDetailPage::class, ['gitRepository' => $repo])
            ->assertSet('testRatchetMode', TestRatchetMode::Soft->value);
    }

    public function test_mount_hydrates_existing_value(): void
    {
        $repo = $this->makeRepo(['config' => ['test_ratchet_mode' => TestRatchetMode::Hard->value]]);

        Livewire::test(GitRepositoryDetailPage::class, ['gitRepository' => $repo])
            ->assertSet('testRatchetMode', TestRatchetMode::Hard->value);
    }

    public function test_save_persists_to_config_jsonb(): void
    {
        $repo = $this->makeRepo(['config' => ['unrelated' => 'keep']]);

        Livewire::test(GitRepositoryDetailPage::class, ['gitRepository' => $repo])
            ->set('testRatchetMode', TestRatchetMode::Hard->value)
            ->call('saveTestRatchetMode')
            ->assertSet('testRatchetSavedMessage', 'Test ratchet mode set to '.TestRatchetMode::Hard->label().'.');

        $repo->refresh();
        $this->assertSame(TestRatchetMode::Hard->value, $repo->config['test_ratchet_mode']);
        $this->assertSame('keep', $repo->config['unrelated']);
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $repo = $this->makeRepo();

        Livewire::test(GitRepositoryDetailPage::class, ['gitRepository' => $repo])
            ->set('testRatchetMode', 'invalid_value')
            ->call('saveTestRatchetMode')
            ->assertHasErrors(['testRatchetMode']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeRepo(array $overrides = []): GitRepository
    {
        return GitRepository::create(array_merge([
            'team_id' => $this->team->id,
            'name' => 'repo-'.bin2hex(random_bytes(3)),
            'url' => 'https://github.com/example/repo.git',
            'provider' => 'generic',
            'mode' => 'api_only',
            'status' => 'active',
            'config' => [],
        ], $overrides))->refresh();
    }
}
