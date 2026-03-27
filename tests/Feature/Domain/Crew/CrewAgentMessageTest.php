<?php

namespace Tests\Feature\Domain\Crew;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\GetAgentMessagesAction;
use App\Domain\Crew\Actions\SendAgentMessageAction;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewAgentMessage;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Crew\CrewGetMessagesTool;
use App\Mcp\Tools\Crew\CrewSendMessageTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

class CrewAgentMessageTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private CrewExecution $execution;

    private Agent $agentA;

    private Agent $agentB;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Crew Message Test Team',
            'slug' => 'crew-message-test',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $this->agentA = Agent::factory()->create(['team_id' => $this->team->id, 'name' => 'Agent Alpha']);
        $this->agentB = Agent::factory()->create(['team_id' => $this->team->id, 'name' => 'Agent Beta']);

        $crew = Crew::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $user->id,
            'coordinator_agent_id' => $this->agentA->id,
            'qa_agent_id' => $this->agentB->id,
        ]);

        $this->execution = CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $crew->id,
            'goal' => 'Test goal',
            'status' => CrewExecutionStatus::Executing,
            'config_snapshot' => [
                'process_type' => CrewProcessType::Parallel->value,
                'coordinator' => ['id' => $this->agentA->id, 'name' => 'Agent Alpha'],
                'workers' => [],
            ],
            'total_cost_credits' => 0,
            'coordinator_iterations' => 0,
            'delegation_depth' => 0,
        ]);

        app()->instance('mcp.team_id', $this->team->id);
    }

    // -------------------------------------------------------------------------
    // SendAgentMessageAction
    // -------------------------------------------------------------------------

    public function test_send_agent_message_action_creates_message(): void
    {
        $action = new SendAgentMessageAction;

        $message = $action->execute(
            execution: $this->execution,
            messageType: 'finding',
            content: 'My hypothesis is X.',
            sender: $this->agentA,
            recipient: $this->agentB,
            round: 1,
        );

        $this->assertInstanceOf(CrewAgentMessage::class, $message);
        $this->assertEquals('finding', $message->message_type);
        $this->assertEquals('My hypothesis is X.', $message->content);
        $this->assertEquals(1, $message->round);
        $this->assertEquals($this->agentA->id, $message->sender_agent_id);
        $this->assertEquals($this->agentB->id, $message->recipient_agent_id);
        $this->assertEquals($this->execution->id, $message->crew_execution_id);
        $this->assertEquals($this->team->id, $message->team_id);
        $this->assertFalse($message->is_read);
    }

    public function test_send_agent_message_action_creates_broadcast_with_null_recipient(): void
    {
        $action = new SendAgentMessageAction;

        $message = $action->execute(
            execution: $this->execution,
            messageType: 'broadcast',
            content: 'Attention all agents!',
            sender: $this->agentA,
            round: 0,
        );

        $this->assertEquals('broadcast', $message->message_type);
        $this->assertNull($message->recipient_agent_id);
    }

    // -------------------------------------------------------------------------
    // GetAgentMessagesAction
    // -------------------------------------------------------------------------

    public function test_get_agent_messages_action_filters_by_round(): void
    {
        $action = new SendAgentMessageAction;
        $action->execute($this->execution, 'finding', 'Round 1 finding', round: 1);
        $action->execute($this->execution, 'finding', 'Round 2 finding', round: 2);

        $getAction = new GetAgentMessagesAction;

        $round1 = $getAction->execute($this->execution, round: 1);
        $this->assertCount(1, $round1);
        $this->assertEquals('Round 1 finding', $round1->first()->content);

        $round2 = $getAction->execute($this->execution, round: 2);
        $this->assertCount(1, $round2);
        $this->assertEquals('Round 2 finding', $round2->first()->content);
    }

    public function test_get_agent_messages_action_includes_broadcasts_for_recipient(): void
    {
        $action = new SendAgentMessageAction;
        // Broadcast (null recipient)
        $action->execute($this->execution, 'broadcast', 'Broadcast message', round: 1);
        // Directed to agentB
        $action->execute($this->execution, 'finding', 'Direct to B', recipient: $this->agentB, round: 1);
        // Directed to agentA only
        $action->execute($this->execution, 'query', 'Only for A', recipient: $this->agentA, round: 1);

        $getAction = new GetAgentMessagesAction;

        // agentB should see: the broadcast + the direct message
        $messages = $getAction->execute($this->execution, recipientAgentId: $this->agentB->id, includebroadcast: true);
        $this->assertCount(2, $messages);

        // Without broadcast, only the directed message
        $direct = $getAction->execute($this->execution, recipientAgentId: $this->agentB->id, includebroadcast: false);
        $this->assertCount(1, $direct);
    }

    // -------------------------------------------------------------------------
    // MCP Tool: crew_send_message
    // -------------------------------------------------------------------------

    public function test_mcp_crew_send_message_creates_message(): void
    {
        $tool = app(CrewSendMessageTool::class);

        $response = $tool->handle(new Request([
            'crew_execution_id' => $this->execution->id,
            'message_type' => 'finding',
            'content' => 'MCP finding',
            'sender_agent_id' => $this->agentA->id,
            'round' => 1,
        ]));

        $this->assertFalse($response->isError());

        $data = json_decode((string) $response->content(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('message_id', $data);

        $this->assertDatabaseHas('crew_agent_messages', [
            'crew_execution_id' => $this->execution->id,
            'message_type' => 'finding',
            'content' => 'MCP finding',
        ]);
    }

    public function test_mcp_crew_send_message_returns_error_for_missing_execution(): void
    {
        $tool = app(CrewSendMessageTool::class);

        $response = $tool->handle(new Request([
            'crew_execution_id' => 'non-existent-uuid',
            'message_type' => 'broadcast',
            'content' => 'Hello',
        ]));

        $this->assertTrue($response->isError());
    }

    // -------------------------------------------------------------------------
    // MCP Tool: crew_get_messages
    // -------------------------------------------------------------------------

    public function test_mcp_crew_get_messages_returns_messages(): void
    {
        // Seed some messages
        (new SendAgentMessageAction)->execute($this->execution, 'finding', 'First finding', sender: $this->agentA, round: 1);
        (new SendAgentMessageAction)->execute($this->execution, 'challenge', 'Challenge it!', sender: $this->agentB, round: 1);

        $tool = app(CrewGetMessagesTool::class);

        $response = $tool->handle(new Request([
            'crew_execution_id' => $this->execution->id,
        ]));

        $this->assertFalse($response->isError());

        $data = json_decode((string) $response->content(), true);
        $this->assertEquals(2, $data['count']);
        $this->assertCount(2, $data['messages']);
        $this->assertEquals('finding', $data['messages'][0]['message_type']);
        $this->assertEquals('Agent Alpha', $data['messages'][0]['sender_name']);
    }

    public function test_mcp_crew_get_messages_filters_by_round(): void
    {
        (new SendAgentMessageAction)->execute($this->execution, 'finding', 'Round 1', round: 1);
        (new SendAgentMessageAction)->execute($this->execution, 'finding', 'Round 2', round: 2);

        $tool = app(CrewGetMessagesTool::class);

        $response = $tool->handle(new Request([
            'crew_execution_id' => $this->execution->id,
            'round' => 1,
        ]));

        $data = json_decode((string) $response->content(), true);
        $this->assertEquals(1, $data['count']);
        $this->assertEquals('Round 1', $data['messages'][0]['content']);
    }
}
