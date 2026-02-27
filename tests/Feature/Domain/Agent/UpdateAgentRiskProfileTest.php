<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Agent\Actions\UpdateAgentRiskProfileAction;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\UserNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UpdateAgentRiskProfileTest extends TestCase
{
    use RefreshDatabase;

    private UpdateAgentRiskProfileAction $action;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->action = app(UpdateAgentRiskProfileAction::class);

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    private function makeAgent(array $attributes = []): Agent
    {
        return Agent::factory()->create(array_merge([
            'team_id' => $this->team->id,
            'status' => AgentStatus::Active,
        ], $attributes));
    }

    private function makeExecution(Agent $agent, string $status, ?int $costCredits = 100): AgentExecution
    {
        return AgentExecution::create([
            'agent_id' => $agent->id,
            'team_id' => $agent->team_id,
            'status' => $status,
            'cost_credits' => $costCredits,
            'duration_ms' => 1000,
            'input' => [],
            'output' => [],
        ]);
    }

    public function test_risk_profile_is_computed_and_persisted(): void
    {
        $agent = $this->makeAgent();

        $this->action->execute($agent);

        $agent->refresh();
        $this->assertNotNull($agent->risk_score);
        $this->assertIsArray($agent->risk_profile);
        $this->assertNotNull($agent->risk_profile_updated_at);
        $this->assertArrayHasKey('failure_rate_7d', $agent->risk_profile);
        $this->assertArrayHasKey('risk_factors', $agent->risk_profile);
    }

    public function test_zero_executions_result_in_low_risk_score(): void
    {
        $agent = $this->makeAgent();

        $this->action->execute($agent);

        $agent->refresh();
        // No executions → no failures, no cost, score should be 0
        $this->assertEquals(0.0, $agent->risk_score);
        $this->assertEmpty($agent->risk_profile['risk_factors']);
    }

    public function test_high_failure_rate_increases_risk_score(): void
    {
        $agent = $this->makeAgent();

        // 8 failed, 2 succeeded = 80% failure rate
        for ($i = 0; $i < 8; $i++) {
            $this->makeExecution($agent, 'failed');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->makeExecution($agent, 'completed');
        }

        $this->action->execute($agent);

        $agent->refresh();
        // failure_rate = 0.8, weight = 30 → contribution = 24
        $this->assertGreaterThan(20, $agent->risk_score);
        $this->assertContains('high_failure_rate', $agent->risk_profile['risk_factors']);
    }

    public function test_agent_auto_disabled_when_risk_score_exceeds_80(): void
    {
        $agent = $this->makeAgent(['status' => AgentStatus::Active]);

        // 100% failure rate → failure contribution = 1.0 * 30 = 30
        for ($i = 0; $i < 20; $i++) {
            $this->makeExecution($agent, 'failed', 10000);
        }

        // Add several other agents with much lower cost so this agent is in the
        // top cost percentile (cost_percentile approaches 1.0, weight = 25).
        // With 5 other agents at cost 1 and this agent at cost 10000:
        // lowerCount=5, total=6 → costPercentile = 5/6 ≈ 0.833 → contribution ≈ 20.8
        for ($i = 0; $i < 5; $i++) {
            $other = $this->makeAgent();
            AgentExecution::create([
                'agent_id' => $other->id,
                'team_id' => $other->team_id,
                'status' => 'completed',
                'cost_credits' => 1,
                'duration_ms' => 100,
                'input' => [],
                'output' => [],
            ]);
        }

        // Create an experiment to attach PlaybookSteps
        $experiment = \App\Domain\Experiment\Models\Experiment::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
        ]);

        // Add PlaybookSteps with 100% PII guardrail blocks:
        // pii_detection_rate = 1.0 → contribution = 1.0 * 25 = 25
        // guardrail_block_rate = 1.0 → contribution = 1.0 * 20 = 20
        for ($i = 0; $i < 5; $i++) {
            \App\Domain\Experiment\Models\PlaybookStep::create([
                'experiment_id' => $experiment->id,
                'agent_id' => $agent->id,
                'order' => $i + 1,
                'status' => 'completed',
                'guardrail_result' => ['safe' => false, 'reason' => 'PII detected in output'],
                'conditions' => [],
                'input_mapping' => [],
            ]);
        }

        // Expected score:
        //   failure_rate_7d (1.0) * 30   = 30.0
        //   cost_percentile (~0.833) * 25 ≈ 20.8
        //   pii_detection_rate (1.0) * 25 = 25.0
        //   guardrail_block_rate (1.0) * 20 = 20.0
        //   Total ≈ 95.8 → well above 80

        $this->action->execute($agent);

        $agent->refresh();

        // Risk score must exceed 80 with this data set
        $this->assertGreaterThan(80, $agent->risk_score, 'Risk score should exceed 80 with 100% failure, high cost, and 100% PII/guardrail blocks');

        // Auto-disable should have fired
        $this->assertEquals(AgentStatus::Disabled, $agent->status);

        // Team notification should have been created
        $notification = UserNotification::where('user_id', $this->user->id)
            ->where('type', 'agent.risk.high')
            ->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString($agent->name, $notification->title);
    }

    public function test_risk_factors_include_high_failure_rate_flag(): void
    {
        $agent = $this->makeAgent();

        // > 20% failure rate triggers 'high_failure_rate' factor
        for ($i = 0; $i < 5; $i++) {
            $this->makeExecution($agent, 'failed');
        }
        for ($i = 0; $i < 10; $i++) {
            $this->makeExecution($agent, 'completed');
        }

        $this->action->execute($agent);

        $agent->refresh();
        // 5/15 ≈ 33% > 20% threshold
        $this->assertContains('high_failure_rate', $agent->risk_profile['risk_factors']);
    }
}
