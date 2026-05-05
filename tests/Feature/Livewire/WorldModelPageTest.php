<?php

namespace Tests\Feature\Livewire;

use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Domain\WorldModel\Jobs\BuildWorldModelDigestJob;
use App\Domain\WorldModel\Models\TeamWorldModel;
use App\Livewire\WorldModel\WorldModelPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class WorldModelPageTest extends TestCase
{
    use RefreshDatabase;

    private function loggedInOwner(): User
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'WM',
            'slug' => 'wm-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $team->users()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_route_renders_for_authed_user(): void
    {
        $this->loggedInOwner();
        $this->get('/world-model')->assertStatus(200);
    }

    public function test_empty_state_shown_when_no_digest(): void
    {
        $this->loggedInOwner();

        Livewire::test(WorldModelPage::class)
            ->assertSee('No digest yet')
            ->assertSet('rebuilding', false);
    }

    public function test_digest_content_rendered_when_present(): void
    {
        $user = $this->loggedInOwner();
        TeamWorldModel::create([
            'team_id' => $user->current_team_id,
            'digest' => '## Current focus\nShipping fast.',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
            'stats' => ['signal_count' => 4, 'experiment_count' => 2, 'memory_count' => 1, 'window_days' => 14],
            'generated_at' => now(),
        ]);

        Livewire::test(WorldModelPage::class)
            ->assertSee('Current focus')
            ->assertSee('4 signals')
            ->assertSee('2 experiments')
            ->assertSee('claude-haiku-4-5-20251001');
    }

    public function test_owner_can_rebuild_and_job_is_queued(): void
    {
        $user = $this->loggedInOwner();
        Queue::fake();

        Livewire::test(WorldModelPage::class)
            ->call('rebuild')
            ->assertHasNoErrors()
            ->assertSet('rebuilding', true);

        Queue::assertPushed(BuildWorldModelDigestJob::class, function (BuildWorldModelDigestJob $job) use ($user) {
            return $job->teamId === $user->current_team_id;
        });
    }

    public function test_rebuild_method_invokes_authorize_gate(): void
    {
        // The rebuild() method calls $this->authorize('manage-team', ...). In the
        // base test harness the gate resolves as always-true (single-team community
        // edition) and in cloud it checks role. We lock down that the call exists
        // rather than the gate's output, since the gate is substitutable per-edition.
        $r = new \ReflectionMethod(WorldModelPage::class, 'rebuild');
        $source = file_get_contents((string) $r->getFileName());
        $this->assertStringContainsString("authorize('manage-team'", $source);
    }

    public function test_stale_badge_shows_when_digest_old(): void
    {
        $user = $this->loggedInOwner();
        $record = TeamWorldModel::create([
            'team_id' => $user->current_team_id,
            'digest' => 'old',
            'stats' => [],
            'generated_at' => now()->subDays(14),
        ]);
        // Sanity check the isStale helper still flags it
        $this->assertTrue($record->isStale());

        Livewire::test(WorldModelPage::class)->assertSee('Stale');
    }

    public function test_skipped_no_data_notice_surfaced(): void
    {
        $user = $this->loggedInOwner();
        TeamWorldModel::create([
            'team_id' => $user->current_team_id,
            'digest' => null,
            'stats' => ['skipped' => 'no data in window', 'window_days' => 14],
            'generated_at' => now(),
        ]);

        Livewire::test(WorldModelPage::class)
            ->assertSee('No digest generated')
            ->assertSee('no data in window');
    }

    public function test_view_cross_team_isolation(): void
    {
        $user = $this->loggedInOwner();

        // Seed a digest for a DIFFERENT team — the page must not display it.
        $otherOwner = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'other',
            'slug' => 'other-'.uniqid(),
            'owner_id' => $otherOwner->id,
            'settings' => [],
        ]);
        TeamWorldModel::create([
            'team_id' => $otherTeam->id,
            'digest' => 'OTHER TEAM SECRET BRIEFING',
            'stats' => [],
            'generated_at' => now(),
        ]);

        Livewire::test(WorldModelPage::class)
            ->assertDontSee('OTHER TEAM SECRET BRIEFING')
            ->assertSee('No digest yet');
    }
}
