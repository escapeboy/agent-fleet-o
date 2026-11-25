<?php

namespace Tests\Feature\Domain\Crew;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Jobs\ExecuteCrewTaskJob;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewMember;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Services\AgentToolAllowlistContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class ExecuteCrewTaskJobToolAllowlistTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    private Crew $crew;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Allowlist Test Team',
            'slug' => 'allowlist-test-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $this->crew = Crew::factory()->create([
            'team_id' => $this->team->id,
            'coordinator_agent_id' => $this->agent->id,
            'qa_agent_id' => $this->agent->id,
        ]);
    }

    public function test_allowlist_is_in_context_while_agent_executes(): void
    {
        $toolId = (string) Str::uuid();
        $this->makeMember(['tool_allowlist' => [$toolId]]);

        $seen = $this->runJob();

        $this->assertSame([$toolId], $seen['context']);
        $this->assertSame([$toolId], $seen['arg']);
        $this->assertNull(app(AgentToolAllowlistContext::class)->allowedToolIdsFor($this->agent->id));
    }

    public function test_no_allowlist_leaves_context_unrestricted(): void
    {
        $this->makeMember([]);

        $seen = $this->runJob();

        $this->assertNull($seen['context']);
        $this->assertNull($seen['arg']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makeMember(array $config): void
    {
        CrewMember::create([
            'crew_id' => $this->crew->id,
            'agent_id' => $this->agent->id,
            'role' => CrewMemberRole::Worker,
            'sort_order' => 0,
            'config' => $config,
        ]);
    }

    /**
     * @return array{context: mixed, arg: mixed}
     */
    private function runJob(): array
    {
        Bus::fake();

        $execution = CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $this->crew->id,
            'status' => CrewExecutionStatus::Executing,
            'goal' => 'Test tool allowlist',
            'config_snapshot' => ['process_type' => CrewProcessType::Sequential->value],
            'started_at' => now(),
        ]);

        $task = CrewTaskExecution::create([
            'crew_execution_id' => $execution->id,
            'agent_id' => $this->agent->id,
            'title' => 'Task 1',
            'description' => 'Task 1 description',
            'status' => CrewTaskStatus::Assigned,
            'depends_on' => [],
            'sort_order' => 1,
        ]);

        $seen = ['context' => 'not called', 'arg' => 'not called'];
        $agentId = $this->agent->id;

        $this->mock(ExecuteAgentAction::class, function (MockInterface $mock) use (&$seen, $agentId) {
            $mock->shouldReceive('execute')->once()->andReturnUsing(
                function (...$args) use (&$seen, $agentId) {
                    $seen['context'] = app(AgentToolAllowlistContext::class)->allowedToolIdsFor($agentId);
                    $seen['arg'] = $args[7] ?? null;

                    return ['output' => ['result' => 'ok'], 'execution' => (object) ['cost_credits' => 0]];
                },
            );
        });

        $job = new ExecuteCrewTaskJob(
            crewExecutionId: $execution->id,
            taskExecutionId: $task->id,
            teamId: $this->team->id,
        );
        $job->handle(app(ExecuteAgentAction::class));

        $this->assertSame(CrewTaskStatus::Completed, $task->fresh()?->status);

        return $seen;
    }
}
