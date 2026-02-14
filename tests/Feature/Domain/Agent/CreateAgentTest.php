<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Agent\Actions\CreateAgentAction;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAgentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    public function test_creates_agent_with_required_fields(): void
    {
        $action = new CreateAgentAction;

        $agent = $action->execute(
            name: 'Test Agent',
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            teamId: $this->team->id,
        );

        $this->assertInstanceOf(Agent::class, $agent);
        $this->assertEquals('Test Agent', $agent->name);
        $this->assertEquals('test-agent', $agent->slug);
        $this->assertEquals('anthropic', $agent->provider);
        $this->assertEquals(AgentStatus::Active, $agent->status);
        $this->assertEquals(0, $agent->budget_spent_credits);
    }

    public function test_creates_agent_with_role_and_goal(): void
    {
        $action = new CreateAgentAction;

        $agent = $action->execute(
            name: 'Analyst Agent',
            provider: 'openai',
            model: 'gpt-4o',
            teamId: $this->team->id,
            role: 'Data Analyst',
            goal: 'Analyze market trends',
            backstory: 'Expert in financial analysis',
        );

        $this->assertEquals('Data Analyst', $agent->role);
        $this->assertEquals('Analyze market trends', $agent->goal);
        $this->assertEquals('Expert in financial analysis', $agent->backstory);
    }

    public function test_creates_agent_with_budget_cap(): void
    {
        $action = new CreateAgentAction;

        $agent = $action->execute(
            name: 'Budget Agent',
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            teamId: $this->team->id,
            budgetCapCredits: 5000,
        );

        $this->assertEquals(5000, $agent->budget_cap_credits);
    }

    public function test_generates_slug_from_name(): void
    {
        $action = new CreateAgentAction;

        $agent = $action->execute(
            name: 'My Special Agent Name',
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            teamId: $this->team->id,
        );

        $this->assertEquals('my-special-agent-name', $agent->slug);
    }

    public function test_agent_belongs_to_team(): void
    {
        $action = new CreateAgentAction;

        $agent = $action->execute(
            name: 'Team Agent',
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            teamId: $this->team->id,
        );

        $this->assertEquals($this->team->id, $agent->team_id);
        $this->assertDatabaseHas('agents', [
            'id' => $agent->id,
            'team_id' => $this->team->id,
        ]);
    }
}
