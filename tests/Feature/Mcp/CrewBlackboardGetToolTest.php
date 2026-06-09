<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewChatMessage;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Crew\CrewBlackboardGetTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class CrewBlackboardGetToolTest extends TestCase
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

    public function test_it_returns_chat_messages_for_the_teams_execution(): void
    {
        $execution = $this->makeExecution($this->team);
        CrewChatMessage::create([
            'crew_execution_id' => $execution->id,
            'agent_name' => 'Agent Alpha',
            'role' => 'assistant',
            'content' => 'I propose we split the work.',
            'round' => 1,
            'metadata' => [],
        ]);

        $result = $this->decode((new CrewBlackboardGetTool)->handle(new Request([
            'execution_id' => $execution->id,
        ])));

        $this->assertSame($execution->id, $result['execution_id']);
        $this->assertSame(1, $result['chat_message_count']);
        $this->assertSame('I propose we split the work.', $result['chat_messages'][0]['content']);
    }

    public function test_it_does_not_return_another_teams_execution(): void
    {
        $otherTeam = Team::factory()->create();
        $otherExecution = $this->makeExecution($otherTeam);

        $result = $this->decode((new CrewBlackboardGetTool)->handle(new Request([
            'execution_id' => $otherExecution->id,
        ])));

        $this->assertArrayHasKey('error', $result);
    }

    private function makeExecution(Team $team): CrewExecution
    {
        $agent = Agent::factory()->for($team)->create();
        $crew = Crew::factory()->for($team)->create([
            'coordinator_agent_id' => $agent->id,
            'qa_agent_id' => $agent->id,
        ]);

        return CrewExecution::create([
            'team_id' => $team->id,
            'crew_id' => $crew->id,
            'goal' => 'Investigate the bug',
            'status' => CrewExecutionStatus::Executing,
            'config_snapshot' => [],
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
