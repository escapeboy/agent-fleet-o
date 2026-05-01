<?php

namespace Tests\Feature\Domain\Crew;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Crew\Services\CrewOrchestrator;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CrewBoundedConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Concurrency Test Team',
            'slug' => 'concurrency-test-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
    }

    public function test_dispatch_parallel_respects_max_parallel_tasks(): void
    {
        Bus::fake();

        $agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $crew = Crew::factory()->create(['team_id' => $this->team->id]);

        $execution = CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $crew->id,
            'status' => CrewExecutionStatus::Executing,
            'goal' => 'Test bounded concurrency',
            'config_snapshot' => [
                'process_type' => CrewProcessType::Parallel->value,
                'max_parallel_tasks' => 3,
            ],
            'started_at' => now(),
        ]);

        // Create 6 ready tasks (more than max_parallel_tasks of 3)
        for ($i = 1; $i <= 6; $i++) {
            CrewTaskExecution::create([
                'team_id' => $this->team->id,
                'crew_execution_id' => $execution->id,
                'agent_id' => $agent->id,
                'task_index' => $i,
                'title' => "Task {$i}",
                'description' => "Task {$i} description",
                'status' => CrewTaskStatus::Pending,
                'dependencies' => [],
            ]);
        }

        $orchestrator = app(CrewOrchestrator::class);
        $orchestrator->dispatchParallel($execution, new \App\Domain\Crew\Services\CrewExecutionScope($execution));

        // Only 3 jobs should be in the batch (max_parallel_tasks = 3)
        Bus::assertBatchCount(1);
        $batches = Bus::dispatchedBatches();
        $this->assertCount(3, $batches[0]->jobs);
    }

    public function test_dispatch_parallel_uses_default_10_when_not_configured(): void
    {
        Bus::fake();

        $agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $crew = Crew::factory()->create(['team_id' => $this->team->id]);

        $execution = CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $crew->id,
            'status' => CrewExecutionStatus::Executing,
            'goal' => 'Test default concurrency',
            'config_snapshot' => [
                'process_type' => CrewProcessType::Parallel->value,
                // No max_parallel_tasks — should default to 10
            ],
            'started_at' => now(),
        ]);

        // Create 12 ready tasks
        for ($i = 1; $i <= 12; $i++) {
            CrewTaskExecution::create([
                'team_id' => $this->team->id,
                'crew_execution_id' => $execution->id,
                'agent_id' => $agent->id,
                'task_index' => $i,
                'title' => "Task {$i}",
                'description' => "Task {$i} description",
                'status' => CrewTaskStatus::Pending,
                'dependencies' => [],
            ]);
        }

        $orchestrator = app(CrewOrchestrator::class);
        $orchestrator->dispatchParallel($execution, new \App\Domain\Crew\Services\CrewExecutionScope($execution));

        Bus::assertBatchCount(1);
        $batches = Bus::dispatchedBatches();
        $this->assertCount(10, $batches[0]->jobs);
    }
}
