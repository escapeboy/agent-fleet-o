<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Shared\Models\Team;
use App\Livewire\Agents\AgentSlaPanel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class AgentSlaPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'SLA Test Team',
            'slug' => 'sla-test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        $this->agent = Agent::factory()->for($this->team)->create();

        Cache::flush();
    }

    private function makeExecution(array $overrides = []): AgentExecution
    {
        return AgentExecution::create(array_merge([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'status' => 'success',
            'duration_ms' => 1500,
            'cost_credits' => 25,
            'tool_calls_count' => 0,
            'llm_steps_count' => 1,
        ], $overrides));
    }

    public function test_renders_empty_state_when_no_executions(): void
    {
        Livewire::test(AgentSlaPanel::class, ['agent' => $this->agent])
            ->assertSet('sla.total_runs', 0)
            ->assertSet('sla.success_rate', null)
            ->assertSee('No runs yet');
    }

    public function test_computes_success_rate(): void
    {
        $this->makeExecution(['status' => 'success']);
        $this->makeExecution(['status' => 'success']);
        $this->makeExecution(['status' => 'failed']);

        Livewire::test(AgentSlaPanel::class, ['agent' => $this->agent])
            ->tap(function ($component) {
                $sla = $component->get('sla');
                $this->assertSame(3, $sla['total_runs']);
                $this->assertEqualsWithDelta(66.7, (float) $sla['success_rate'], 0.05);
            });
    }

    public function test_computes_latency_p95(): void
    {
        // 10 runs with durations 1000, 2000, ..., 10000
        for ($i = 1; $i <= 10; $i++) {
            $this->makeExecution(['duration_ms' => $i * 1000]);
        }

        Livewire::test(AgentSlaPanel::class, ['agent' => $this->agent])
            ->tap(function ($component) {
                $sla = $component->get('sla');
                // p95 of [1000..10000] sorted is the 9th (0-indexed floor(0.95*9)=8 => 9000)
                $this->assertSame(9000, $sla['latency_p95_ms']);
            });
    }

    public function test_computes_average_cost(): void
    {
        $this->makeExecution(['cost_credits' => 10]);
        $this->makeExecution(['cost_credits' => 20]);
        $this->makeExecution(['cost_credits' => 30]);

        Livewire::test(AgentSlaPanel::class, ['agent' => $this->agent])
            ->assertSet('sla.avg_cost_credits', 20);
    }

    public function test_excludes_executions_older_than_7_days(): void
    {
        $old = $this->makeExecution(['status' => 'failed']);
        $old->created_at = now()->subDays(10);
        $old->saveQuietly();

        $this->makeExecution(['status' => 'success']);

        Livewire::test(AgentSlaPanel::class, ['agent' => $this->agent])
            ->assertSet('sla.total_runs', 1)
            ->assertSet('sla.success_rate', 100.0);
    }

    public function test_does_not_leak_other_team_data(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other',
            'slug' => 'other-sla',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);

        AgentExecution::create([
            'team_id' => $otherTeam->id,
            'agent_id' => $this->agent->id,  // same agent_id, different team
            'status' => 'failed',
            'duration_ms' => 9999,
            'cost_credits' => 999,
            'tool_calls_count' => 0,
            'llm_steps_count' => 1,
        ]);

        Livewire::test(AgentSlaPanel::class, ['agent' => $this->agent])
            ->assertSet('sla.total_runs', 0);
    }

    public function test_health_score_is_high_for_healthy_agent(): void
    {
        // 100% success, fast, cheap
        for ($i = 0; $i < 5; $i++) {
            $this->makeExecution(['status' => 'success', 'duration_ms' => 800, 'cost_credits' => 20]);
        }

        Livewire::test(AgentSlaPanel::class, ['agent' => $this->agent])
            ->tap(function ($component) {
                $score = $component->get('sla.health_score');
                $this->assertGreaterThanOrEqual(0.9, (float) $score);
            });
    }

    public function test_health_score_drops_for_failing_agent(): void
    {
        // 0% success
        for ($i = 0; $i < 5; $i++) {
            $this->makeExecution(['status' => 'failed', 'duration_ms' => 800, 'cost_credits' => 20]);
        }

        Livewire::test(AgentSlaPanel::class, ['agent' => $this->agent])
            ->tap(function ($component) {
                $score = $component->get('sla.health_score');
                // success * 0.6 = 0; latency + cost = 0.4 max → expect ≤ 0.5
                $this->assertLessThanOrEqual(0.5, (float) $score);
            });
    }

    public function test_refresh_recomputes(): void
    {
        $component = Livewire::test(AgentSlaPanel::class, ['agent' => $this->agent])
            ->assertSet('sla.total_runs', 0);

        $this->makeExecution();
        Cache::flush();

        $component->call('refresh')->assertSet('sla.total_runs', 1);
    }
}
