<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\SendAgentMessageAction;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewChatMessage;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Shared\Models\Team;
use App\Livewire\Crews\CrewChatRoomPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CrewChatRoomPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Crew $crew;

    private CrewExecution $execution;

    private Agent $agentA;

    private Agent $agentB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Chat Room Test Team',
            'slug' => 'chat-room-test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        $this->agentA = Agent::factory()->for($this->team)->create(['name' => 'Agent Alpha']);
        $this->agentB = Agent::factory()->for($this->team)->create(['name' => 'Agent Beta']);

        $this->crew = Crew::factory()->for($this->team)->create([
            'user_id' => $this->user->id,
            'coordinator_agent_id' => $this->agentA->id,
            'qa_agent_id' => $this->agentB->id,
        ]);

        $this->execution = CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $this->crew->id,
            'goal' => 'Investigate the bug',
            'status' => CrewExecutionStatus::Executing,
            'config_snapshot' => [
                'process_type' => CrewProcessType::Parallel->value,
            ],
            'total_cost_credits' => 0,
            'coordinator_iterations' => 0,
            'delegation_depth' => 0,
        ]);
    }

    public function test_it_shows_a_crew_executions_inter_agent_messages(): void
    {
        $send = new SendAgentMessageAction;
        $send->execute($this->execution, 'finding', 'Alpha hypothesis is X.', sender: $this->agentA, recipient: $this->agentB, round: 1);
        $send->execute($this->execution, 'broadcast', 'Heads up everyone.', sender: $this->agentA, round: 1);

        Livewire::test(CrewChatRoomPage::class, ['execution' => $this->execution->id])
            ->assertOk()
            ->assertSee('Inter-Agent Messages')
            ->assertSee('Alpha hypothesis is X.')
            ->assertSee('Heads up everyone.')
            ->assertSee('Agent Alpha')
            ->assertSee('Agent Beta')
            ->assertSee('broadcast');
    }

    public function test_it_shows_chat_room_transcript(): void
    {
        CrewChatMessage::create([
            'crew_execution_id' => $this->execution->id,
            'agent_id' => $this->agentA->id,
            'agent_name' => 'Agent Alpha',
            'role' => 'assistant',
            'content' => 'I propose we split the work.',
            'round' => 1,
            'metadata' => [],
        ]);

        Livewire::test(CrewChatRoomPage::class, ['execution' => $this->execution->id])
            ->assertOk()
            ->assertSee('Chat Room Discussion')
            ->assertSee('I propose we split the work.');
    }

    public function test_it_cannot_view_another_teams_execution(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::factory()->create(['owner_id' => $otherUser->id]);

        $otherAgent = Agent::factory()->for($otherTeam)->create();
        $otherCrew = Crew::factory()->for($otherTeam)->create([
            'user_id' => $otherUser->id,
            'coordinator_agent_id' => $otherAgent->id,
            'qa_agent_id' => $otherAgent->id,
        ]);

        $otherExecution = CrewExecution::create([
            'team_id' => $otherTeam->id,
            'crew_id' => $otherCrew->id,
            'goal' => 'Secret goal',
            'status' => CrewExecutionStatus::Executing,
            'config_snapshot' => [],
            'total_cost_credits' => 0,
            'coordinator_iterations' => 0,
            'delegation_depth' => 0,
        ]);

        // Current user (acting as $this->user / $this->team) must not resolve
        // another team's execution — the global TeamScope returns null and the
        // page aborts 404.
        Livewire::test(CrewChatRoomPage::class, ['execution' => $otherExecution->id])
            ->assertStatus(404);
    }

    public function test_it_404s_for_unknown_execution_id(): void
    {
        Livewire::test(CrewChatRoomPage::class, ['execution' => '00000000-0000-0000-0000-000000000000'])
            ->assertStatus(404);
    }
}
