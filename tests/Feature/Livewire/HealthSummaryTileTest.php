<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Models\CircuitBreakerState;
use App\Livewire\Dashboard\HealthSummaryTile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class HealthSummaryTileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Health Tile Team',
            'slug' => 'health-tile-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        Cache::flush();
    }

    private function makeExperiment(ExperimentStatus $status, array $overrides = []): Experiment
    {
        return Experiment::create(array_merge([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Test',
            'thesis' => 't',
            'status' => $status,
            'track' => 'growth',
            'budget_cap_credits' => 5000,
            'max_iterations' => 3,
            'current_iteration' => 1,
            'max_outbound_count' => 100,
            'outbound_count' => 0,
        ], $overrides));
    }

    public function test_renders_healthy_state_when_zero_counters(): void
    {
        Livewire::test(HealthSummaryTile::class)
            ->assertSet('counts.failed_24h', 0)
            ->assertSet('counts.stuck_now', 0)
            ->assertSet('counts.circuit_open', 0)
            ->assertSet('counts.paused', 0)
            ->assertSee('All systems healthy');
    }

    public function test_counts_failed_experiments_in_last_24h(): void
    {
        $this->makeExperiment(ExperimentStatus::BuildingFailed);
        $this->makeExperiment(ExperimentStatus::PlanningFailed);
        // older than 24h — should NOT count
        $old = $this->makeExperiment(ExperimentStatus::ScoringFailed);
        $old->updated_at = now()->subDays(2);
        $old->saveQuietly();

        Livewire::test(HealthSummaryTile::class)
            ->assertSet('counts.failed_24h', 2)
            ->assertSee('Attention needed')
            ->assertSee('Triage now');
    }

    public function test_counts_paused_experiments(): void
    {
        $this->makeExperiment(ExperimentStatus::Paused);
        $this->makeExperiment(ExperimentStatus::Paused);

        Livewire::test(HealthSummaryTile::class)
            ->assertSet('counts.paused', 2);
    }

    public function test_counts_open_circuit_breakers(): void
    {
        $agent = Agent::factory()->for($this->team)->create();
        CircuitBreakerState::create([
            'team_id' => $this->team->id,
            'agent_id' => $agent->id,
            'state' => 'open',
            'failure_count' => 5,
            'success_count' => 0,
            'cooldown_seconds' => 60,
            'failure_threshold' => 5,
            'opened_at' => now(),
        ]);

        Livewire::test(HealthSummaryTile::class)
            ->assertSet('counts.circuit_open', 1);
    }

    public function test_does_not_leak_other_team_data(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team-tile',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);

        Experiment::create([
            'team_id' => $otherTeam->id,
            'user_id' => $otherUser->id,
            'title' => 'Their failure',
            'thesis' => 't',
            'status' => ExperimentStatus::BuildingFailed,
            'track' => 'growth',
            'budget_cap_credits' => 5000,
            'max_iterations' => 3,
            'current_iteration' => 1,
            'max_outbound_count' => 100,
            'outbound_count' => 0,
        ]);

        Livewire::test(HealthSummaryTile::class)
            ->assertSet('counts.failed_24h', 0);
    }

    public function test_stuck_experiment_counted(): void
    {
        config()->set('experiments.recovery.timeouts', [
            'building' => 60,  // 1 minute timeout for test
        ]);

        $exp = $this->makeExperiment(ExperimentStatus::Building);
        $exp->updated_at = now()->subMinutes(30);
        $exp->saveQuietly();

        Livewire::test(HealthSummaryTile::class)
            ->assertSet('counts.stuck_now', 1);
    }

    public function test_refresh_updates_counts(): void
    {
        $component = Livewire::test(HealthSummaryTile::class)
            ->assertSet('counts.failed_24h', 0);

        $this->makeExperiment(ExperimentStatus::BuildingFailed);

        // Without cache flush, the 30s remember will return stale data —
        // this proves the cache is doing its job. Flush, then refresh.
        Cache::flush();

        $component->call('refresh')->assertSet('counts.failed_24h', 1);
    }
}
