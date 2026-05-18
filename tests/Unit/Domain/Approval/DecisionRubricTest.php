<?php

namespace Tests\Unit\Domain\Approval;

use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Approval\Services\DecisionRubric;
use Tests\TestCase;

class DecisionRubricTest extends TestCase
{
    private function proposal(array $attributes = []): ActionProposal
    {
        $proposal = new ActionProposal;
        $proposal->risk_level = $attributes['risk_level'] ?? 'medium';
        $proposal->payload = $attributes['payload'] ?? [];
        $proposal->actor_user_id = $attributes['actor_user_id'] ?? null;
        $proposal->actor_agent_id = $attributes['actor_agent_id'] ?? null;

        return $proposal;
    }

    public function test_risk_dimension_maps_each_level(): void
    {
        $rubric = app(DecisionRubric::class);

        $this->assertSame(5, $rubric->evaluate($this->proposal(['risk_level' => 'low']))->risk);
        $this->assertSame(3, $rubric->evaluate($this->proposal(['risk_level' => 'medium']))->risk);
        $this->assertSame(2, $rubric->evaluate($this->proposal(['risk_level' => 'high']))->risk);
        $this->assertSame(1, $rubric->evaluate($this->proposal(['risk_level' => 'critical']))->risk);
    }

    public function test_cost_dimension_buckets_estimated_credits(): void
    {
        $rubric = app(DecisionRubric::class);

        $this->assertSame(5, $rubric->evaluate($this->proposal(['payload' => ['estimated_credits' => 1]]))->cost);
        $this->assertSame(4, $rubric->evaluate($this->proposal(['payload' => ['estimated_credits' => 10]]))->cost);
        $this->assertSame(3, $rubric->evaluate($this->proposal(['payload' => ['estimated_credits' => 100]]))->cost);
        $this->assertSame(2, $rubric->evaluate($this->proposal(['payload' => ['estimated_credits' => 1000]]))->cost);
        $this->assertSame(1, $rubric->evaluate($this->proposal(['payload' => ['estimated_credits' => 5000]]))->cost);
    }

    public function test_cost_dimension_defaults_when_no_estimate(): void
    {
        $this->assertSame(3, app(DecisionRubric::class)->evaluate($this->proposal())->cost);
    }

    public function test_confidence_dimension_reflects_actor_and_risk(): void
    {
        $rubric = app(DecisionRubric::class);

        // User-initiated, low risk → 4 (no penalty).
        $this->assertSame(4, $rubric->evaluate($this->proposal([
            'risk_level' => 'low', 'actor_user_id' => 'u-1',
        ]))->confidence);

        // User-initiated, high risk → 4 - 1 penalty = 3.
        $this->assertSame(3, $rubric->evaluate($this->proposal([
            'risk_level' => 'high', 'actor_user_id' => 'u-1',
        ]))->confidence);

        // Agent-initiated, low risk → 2.
        $this->assertSame(2, $rubric->evaluate($this->proposal([
            'risk_level' => 'low', 'actor_agent_id' => 'a-1',
        ]))->confidence);

        // Agent-initiated, critical risk → 2 - 1 = 1.
        $this->assertSame(1, $rubric->evaluate($this->proposal([
            'risk_level' => 'critical', 'actor_agent_id' => 'a-1',
        ]))->confidence);
    }

    public function test_impact_and_urgency_honour_hints_and_default(): void
    {
        $rubric = app(DecisionRubric::class);

        $hinted = $rubric->evaluate($this->proposal(['payload' => ['impact' => 5, 'urgency' => 1]]));
        $this->assertSame(5, $hinted->impact);
        $this->assertSame(1, $hinted->urgency);

        // Out-of-range hints clamp to 1-5.
        $clamped = $rubric->evaluate($this->proposal(['payload' => ['impact' => 99, 'urgency' => -3]]));
        $this->assertSame(5, $clamped->impact);
        $this->assertSame(1, $clamped->urgency);

        // Missing hints default to a neutral 3.
        $defaulted = $rubric->evaluate($this->proposal());
        $this->assertSame(3, $defaulted->impact);
        $this->assertSame(3, $defaulted->urgency);
    }

    public function test_total_is_the_sum_of_all_dimensions(): void
    {
        $score = app(DecisionRubric::class)->evaluate($this->proposal([
            'risk_level' => 'low',
            'actor_user_id' => 'u-1',
            'payload' => ['estimated_credits' => 1, 'impact' => 5, 'urgency' => 5],
        ]));

        // impact 5 + risk 5 + cost 5 + urgency 5 + confidence 4 = 24.
        $this->assertSame(24, $score->total);
        $this->assertSame(
            $score->impact + $score->risk + $score->cost + $score->urgency + $score->confidence,
            $score->total,
        );
    }

    public function test_recommendation_is_human_review_when_auto_routing_disabled(): void
    {
        config(['decision_rubric.auto_execute.enabled' => false]);
        config(['decision_rubric.auto_reject.enabled' => false]);

        $score = app(DecisionRubric::class)->evaluate($this->proposal([
            'risk_level' => 'low',
            'actor_user_id' => 'u-1',
            'payload' => ['estimated_credits' => 1, 'impact' => 5, 'urgency' => 5],
        ]));

        $this->assertSame(DecisionRubric::HUMAN_REVIEW, $score->recommendation);
    }

    public function test_recommendation_is_auto_execute_above_threshold_when_enabled(): void
    {
        config(['decision_rubric.auto_execute.enabled' => true]);

        $score = app(DecisionRubric::class)->evaluate($this->proposal([
            'risk_level' => 'low',
            'actor_user_id' => 'u-1',
            'payload' => ['estimated_credits' => 1, 'impact' => 5, 'urgency' => 5],
        ]));

        $this->assertSame(DecisionRubric::AUTO_EXECUTE, $score->recommendation);
    }

    public function test_recommendation_is_auto_reject_below_threshold_when_enabled(): void
    {
        config(['decision_rubric.auto_reject.enabled' => true]);

        $score = app(DecisionRubric::class)->evaluate($this->proposal([
            'risk_level' => 'high',
            'actor_agent_id' => 'a-1',
            'payload' => ['estimated_credits' => 5000, 'impact' => 1, 'urgency' => 1],
        ]));

        // impact 1 + risk 2 + cost 1 + urgency 1 + confidence 1 = 6 ≤ 8.
        $this->assertSame(6, $score->total);
        $this->assertSame(DecisionRubric::AUTO_REJECT, $score->recommendation);
    }

    public function test_critical_risk_is_never_auto_routed(): void
    {
        config(['decision_rubric.auto_execute.enabled' => true]);

        $score = app(DecisionRubric::class)->evaluate($this->proposal([
            'risk_level' => 'critical',
            'actor_user_id' => 'u-1',
            'payload' => ['estimated_credits' => 1, 'impact' => 5, 'urgency' => 5],
        ]));

        $this->assertGreaterThanOrEqual(18, $score->total);
        $this->assertSame(DecisionRubric::HUMAN_REVIEW, $score->recommendation);
    }
}
