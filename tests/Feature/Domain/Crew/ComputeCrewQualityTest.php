<?php

namespace Tests\Feature\Domain\Crew;

use App\Domain\Crew\Actions\ComputeCrewQualityAction;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComputeCrewQualityTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;
    private CrewExecution $execution;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Quality Test Team',
            'slug' => 'quality-test',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $crew = Crew::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $user->id,
            'coordinator_agent_id' => $agent->id,
            'qa_agent_id' => $agent->id,
        ]);

        $this->execution = CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $crew->id,
            'goal' => 'Test quality computation',
            'status' => CrewExecutionStatus::Completed,
            'config_snapshot' => [],
        ]);
    }

    private function makeTask(array $overrides = []): array
    {
        return array_merge([
            'crew_execution_id' => $this->execution->id,
            'title' => 'Default Task',
            'description' => 'Default task description',
            'status' => CrewTaskStatus::Validated,
            'qa_score' => 0.8,
            'attempt_number' => 1,
            'max_attempts' => 3,
            'sort_order' => 1,
        ], $overrides);
    }

    public function test_returns_zeros_for_empty_execution(): void
    {
        $dims = app(ComputeCrewQualityAction::class)->execute($this->execution);

        $this->assertEquals(0, $dims['coherence']);
        $this->assertEquals(0, $dims['efficiency']);
        $this->assertEquals(0, $dims['diversity']);
        $this->assertEquals(0, $dims['quality']);
        $this->assertEquals(0, $dims['overall']);
        $this->assertArrayHasKey('computed_at', $dims);
    }

    public function test_coherence_reflects_validated_ratio(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);

        CrewTaskExecution::create($this->makeTask(['title' => 'Task A', 'status' => CrewTaskStatus::Validated, 'qa_score' => 0.9, 'agent_id' => $agent->id, 'attempt_number' => 1, 'sort_order' => 1]));
        CrewTaskExecution::create($this->makeTask(['title' => 'Task B', 'status' => CrewTaskStatus::Validated, 'qa_score' => 0.8, 'agent_id' => $agent->id, 'attempt_number' => 1, 'sort_order' => 2]));
        CrewTaskExecution::create($this->makeTask(['title' => 'Task C', 'status' => CrewTaskStatus::Failed, 'qa_score' => null, 'agent_id' => $agent->id, 'attempt_number' => 2, 'sort_order' => 3]));
        CrewTaskExecution::create($this->makeTask(['title' => 'Task D', 'status' => CrewTaskStatus::Failed, 'qa_score' => null, 'agent_id' => $agent->id, 'attempt_number' => 1, 'sort_order' => 4]));

        $dims = app(ComputeCrewQualityAction::class)->execute($this->execution);

        // 2 validated out of 4 = 0.5
        $this->assertEquals(0.5, $dims['coherence']);
        // avg qa_score of validated tasks = (0.9 + 0.8) / 2 = 0.85
        $this->assertEquals(0.85, $dims['quality']);
        // 1 retry (task C had attempt_number=2) → efficiency = 1 - 1/4 = 0.75
        $this->assertEquals(0.75, $dims['efficiency']);
        // diversity: 1 distinct agent / 4 tasks = 0.25
        $this->assertEquals(0.25, $dims['diversity']);
    }

    public function test_perfect_execution_gives_high_scores(): void
    {
        $agentA = Agent::factory()->create(['team_id' => $this->team->id]);
        $agentB = Agent::factory()->create(['team_id' => $this->team->id]);

        foreach (range(1, 4) as $i) {
            CrewTaskExecution::create($this->makeTask([
                'title' => "Task $i",
                'status' => CrewTaskStatus::Validated,
                'qa_score' => 0.95,
                'agent_id' => $i <= 2 ? $agentA->id : $agentB->id,
                'attempt_number' => 1,
                'sort_order' => $i,
            ]));
        }

        $dims = app(ComputeCrewQualityAction::class)->execute($this->execution);

        $this->assertEquals(1.0, $dims['coherence']);
        $this->assertEquals(1.0, $dims['efficiency']);
        $this->assertEquals(0.5, $dims['diversity']);
        $this->assertEquals(0.95, $dims['quality']);
        $this->assertGreaterThan(0.85, $dims['overall']);
    }

    public function test_dims_are_persisted_on_execution(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);
        CrewTaskExecution::create($this->makeTask(['agent_id' => $agent->id]));

        app(ComputeCrewQualityAction::class)->execute($this->execution);

        $fresh = $this->execution->fresh();
        $this->assertNotNull($fresh->quality_dimensions);
        $this->assertArrayHasKey('coherence', $fresh->quality_dimensions);
        $this->assertArrayHasKey('overall', $fresh->quality_dimensions);
    }

    public function test_efficiency_clamped_at_zero_for_many_retries(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);
        CrewTaskExecution::create($this->makeTask([
            'title' => 'Task A',
            'status' => CrewTaskStatus::QaFailed,
            'qa_score' => null,
            'agent_id' => $agent->id,
            'attempt_number' => 5,
        ]));

        $dims = app(ComputeCrewQualityAction::class)->execute($this->execution);

        $this->assertGreaterThanOrEqual(0, $dims['efficiency']);
    }
}
