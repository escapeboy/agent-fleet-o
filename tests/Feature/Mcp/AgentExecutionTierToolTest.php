<?php

namespace Tests\Feature\Mcp;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Agent\AgentCreateTool;
use App\Mcp\Tools\Agent\AgentUpdateTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

class AgentExecutionTierToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Tier Team',
            'slug' => 'tier-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($user);
        app()->instance('mcp.team_id', $this->team->id);
    }

    public function test_create_sets_execution_tier(): void
    {
        $response = app(AgentCreateTool::class)->handle(new Request([
            'name' => 'Tiered',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'execution_tier' => 'pro',
        ]));

        $this->assertFalse($response->isError());
        $agent = Agent::withoutGlobalScopes()->where('name', 'Tiered')->firstOrFail();
        $this->assertSame('pro', $agent->config['execution_tier'] ?? null);
    }

    public function test_update_sets_and_clears_execution_tier_without_touching_other_keys(): void
    {
        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'config' => ['reasoning_effort' => 'high'],
        ]);

        app(AgentUpdateTool::class)->handle(new Request(['agent_id' => $agent->id, 'execution_tier' => 'ultra']));
        $this->assertSame(['reasoning_effort' => 'high', 'execution_tier' => 'ultra'], $agent->fresh()->config);

        app(AgentUpdateTool::class)->handle(new Request(['agent_id' => $agent->id, 'execution_tier' => '']));
        $this->assertSame(['reasoning_effort' => 'high'], $agent->fresh()->config);
    }
}
