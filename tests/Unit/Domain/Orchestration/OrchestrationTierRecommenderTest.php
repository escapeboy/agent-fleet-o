<?php

namespace Tests\Unit\Domain\Orchestration;

use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Orchestration\Enums\OrchestrationTier;
use App\Domain\Orchestration\Services\OrchestrationTierRecommender;
use Tests\TestCase;

class OrchestrationTierRecommenderTest extends TestCase
{
    private OrchestrationTierRecommender $recommender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recommender = new OrchestrationTierRecommender;
    }

    public function test_no_signals_defaults_to_single_agent(): void
    {
        $r = $this->recommender->recommend('Summarize this article in three bullet points');

        $this->assertSame(OrchestrationTier::SingleAgent->value, $r['tier']);
        $this->assertNull($r['process_type']);
        $this->assertSame('low', $r['confidence']);
    }

    public function test_compare_options_routes_to_crew_fanout(): void
    {
        $r = $this->recommender->recommend('Compare the top options and weigh different perspectives');

        $this->assertSame(OrchestrationTier::Crew->value, $r['tier']);
        $this->assertSame(CrewProcessType::Fanout->value, $r['process_type']);
    }

    public function test_root_cause_routes_to_crew_adversarial(): void
    {
        $r = $this->recommender->recommend('Debate the root cause and challenge each hypothesis');

        $this->assertSame(OrchestrationTier::Crew->value, $r['tier']);
        $this->assertSame(CrewProcessType::Adversarial->value, $r['process_type']);
    }

    public function test_review_for_consensus_routes_to_crew_chatroom(): void
    {
        $r = $this->recommender->recommend('Review and verify the design to reach consensus');

        $this->assertSame(OrchestrationTier::Crew->value, $r['tier']);
        $this->assertSame(CrewProcessType::ChatRoom->value, $r['process_type']);
    }

    public function test_multi_stage_pipeline_routes_to_workflow(): void
    {
        $r = $this->recommender->recommend('Migrate the service step by step across the codebase');

        $this->assertSame(OrchestrationTier::Workflow->value, $r['tier']);
        $this->assertNull($r['process_type']);
    }

    public function test_stages_signal_forces_workflow(): void
    {
        $r = $this->recommender->recommend('Do the thing', ['stages' => 4]);

        $this->assertSame(OrchestrationTier::Workflow->value, $r['tier']);
    }

    public function test_needs_parallel_signal_forces_crew(): void
    {
        $r = $this->recommender->recommend('Do the thing', ['needs_parallel' => true]);

        $this->assertSame(OrchestrationTier::Crew->value, $r['tier']);
        $this->assertSame(CrewProcessType::Fanout->value, $r['process_type']);
    }

    public function test_subtasks_signal_sizes_crew(): void
    {
        $r = $this->recommender->recommend('Compare alternatives', ['subtasks' => 6]);

        $this->assertSame(OrchestrationTier::Crew->value, $r['tier']);
        $this->assertSame(6, $r['estimated_agents']);
    }
}
