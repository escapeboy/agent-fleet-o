<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Agent\Actions\CreateAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentPersonalityTest extends TestCase
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

    public function test_creates_agent_with_personality(): void
    {
        $personality = [
            'tone' => 'professional',
            'communication_style' => 'concise',
            'traits' => ['analytical', 'precise'],
            'behavioral_rules' => ['Always cite sources'],
            'response_format_preference' => 'structured',
        ];

        $agent = app(CreateAgentAction::class)->execute(
            name: 'Personality Agent',
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            teamId: $this->team->id,
            personality: $personality,
        );

        $this->assertIsArray($agent->personality);
        $this->assertEquals('professional', $agent->personality['tone']);
        $this->assertEquals(['analytical', 'precise'], $agent->personality['traits']);
    }

    public function test_creates_agent_without_personality(): void
    {
        $agent = app(CreateAgentAction::class)->execute(
            name: 'No Personality Agent',
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            teamId: $this->team->id,
        );

        $this->assertNull($agent->personality);
    }

    public function test_agent_personality_is_cast_to_array(): void
    {
        $agent = app(CreateAgentAction::class)->execute(
            name: 'Cast Test Agent',
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            teamId: $this->team->id,
            personality: ['tone' => 'friendly'],
        );

        $freshAgent = Agent::find($agent->id);
        $this->assertIsArray($freshAgent->personality);
        $this->assertEquals('friendly', $freshAgent->personality['tone']);
    }

    public function test_personality_can_be_updated(): void
    {
        $agent = app(CreateAgentAction::class)->execute(
            name: 'Update Personality Agent',
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            teamId: $this->team->id,
            personality: ['tone' => 'formal'],
        );

        $agent->update(['personality' => ['tone' => 'casual', 'traits' => ['creative']]]);

        $freshAgent = Agent::find($agent->id);
        $this->assertEquals('casual', $freshAgent->personality['tone']);
        $this->assertEquals(['creative'], $freshAgent->personality['traits']);
    }
}
