<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Jobs\ExecuteCrewJob;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Crew\CrewExecutionResumeTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class CrewExecutionResumeToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $user->id]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($user);
        app()->instance('mcp.team_id', $this->team->id);
    }

    public function test_resume_sets_executing_and_queues_resume_job(): void
    {
        Bus::fake();

        $execution = $this->makeExecution(CrewExecutionStatus::Paused);

        $result = $this->decode((new CrewExecutionResumeTool)->handle(new Request([
            'execution_id' => $execution->id,
        ])));

        $this->assertSame('executing', $result['status']);
        $this->assertSame(CrewExecutionStatus::Executing, $execution->fresh()?->status);
        Bus::assertDispatched(
            ExecuteCrewJob::class,
            fn (ExecuteCrewJob $job) => $job->crewExecutionId === $execution->id && $job->resume === true,
        );
    }

    public function test_resume_rejects_non_paused_execution(): void
    {
        Bus::fake();

        $execution = $this->makeExecution(CrewExecutionStatus::Executing);

        $result = $this->decode((new CrewExecutionResumeTool)->handle(new Request([
            'execution_id' => $execution->id,
        ])));

        $this->assertSame('FAILED_PRECONDITION', $result['error']['code']);
        Bus::assertNotDispatched(ExecuteCrewJob::class);
        $this->assertSame(CrewExecutionStatus::Executing, $execution->fresh()?->status);
    }

    private function makeExecution(CrewExecutionStatus $status): CrewExecution
    {
        $agent = Agent::factory()->for($this->team)->create();
        $crew = Crew::factory()->for($this->team)->create([
            'coordinator_agent_id' => $agent->id,
            'qa_agent_id' => $agent->id,
        ]);

        return CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $crew->id,
            'goal' => 'Resume the paused work',
            'status' => $status,
            'config_snapshot' => ['process_type' => CrewProcessType::Sequential->value],
            'total_cost_credits' => 0,
            'coordinator_iterations' => 0,
            'delegation_depth' => 0,
        ]);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }
}
