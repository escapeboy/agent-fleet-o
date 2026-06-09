<?php

namespace Tests\Feature\Livewire;

use App\Domain\Evaluation\Enums\DriftSignalType;
use App\Domain\Evaluation\Models\DriftSignal;
use App\Domain\Evaluation\Models\EvaluationMonitorSnapshot;
use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Livewire\Evaluation\DriftSignalsPage;
use App\Livewire\Evaluation\EvalMonitorPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EvaluationDashboardsPageTest extends TestCase
{
    use RefreshDatabase;

    private function loggedInOwner(): User
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Eval',
            'slug' => 'eval-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $team->users()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);

        return $user;
    }

    private function otherTeam(): Team
    {
        $otherOwner = User::factory()->create();

        return Team::create([
            'name' => 'Other',
            'slug' => 'other-'.uniqid(),
            'owner_id' => $otherOwner->id,
            'settings' => [],
        ]);
    }

    public function test_drift_route_renders_for_authed_user(): void
    {
        $this->loggedInOwner();
        $this->get('/evaluation/drift')->assertStatus(200);
    }

    public function test_monitor_route_renders_for_authed_user(): void
    {
        $this->loggedInOwner();
        $this->get('/evaluation/monitor')->assertStatus(200);
    }

    public function test_drift_page_shows_current_team_signal(): void
    {
        $user = $this->loggedInOwner();

        DriftSignal::create([
            'team_id' => $user->current_team_id,
            'signal_type' => DriftSignalType::EvalScoreDecay,
            'value' => 0.42,
            'baseline' => 0.80,
            'breached' => true,
            'window' => '7d',
            'detected_at' => now(),
        ]);

        Livewire::test(DriftSignalsPage::class)
            ->assertSee('Eval score decay')
            ->assertSee('Breached');
    }

    public function test_drift_page_cross_team_isolation(): void
    {
        $this->loggedInOwner();
        $other = $this->otherTeam();

        DriftSignal::create([
            'team_id' => $other->id,
            'signal_type' => DriftSignalType::ThumbsDownRate,
            'value' => 0.99,
            'baseline' => 0.10,
            'breached' => true,
            'window' => '1d',
            'detected_at' => now(),
        ]);

        // Current team has zero drift signals, so the empty state proves the other
        // team's row is scoped out. (Asserting on the type label would be brittle —
        // it also appears as a filter <option>.)
        Livewire::test(DriftSignalsPage::class)
            ->assertSee('No drift signals recorded yet.');
    }

    public function test_monitor_page_shows_current_team_snapshot(): void
    {
        $user = $this->loggedInOwner();

        EvaluationMonitorSnapshot::create([
            'team_id' => $user->current_team_id,
            'avg_score' => 7.50,
            'pass_rate' => 91.20,
            'active_count' => 12,
            'deferred_passed' => 3,
            'sampled_count' => 20,
        ]);

        Livewire::test(EvalMonitorPage::class)
            ->assertSee('7.50')
            ->assertSee('91.20%');
    }

    public function test_monitor_page_cross_team_isolation(): void
    {
        $this->loggedInOwner();
        $other = $this->otherTeam();

        EvaluationMonitorSnapshot::create([
            'team_id' => $other->id,
            'avg_score' => 1.11,
            'pass_rate' => 22.22,
            'active_count' => 5,
            'deferred_passed' => 1,
            'sampled_count' => 9,
        ]);

        Livewire::test(EvalMonitorPage::class)
            ->assertDontSee('22.22%')
            ->assertSee('No monitor snapshots in this period.');
    }
}
