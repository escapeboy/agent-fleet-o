<?php

namespace Tests\Feature\Mcp;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Agent\AgentUpdateTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Tests\TestCase;

class AgentUpdateCommandRulesToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Rules Team',
            'slug' => 'rules-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($user);
        app()->instance('mcp.team_id', $this->team->id);
    }

    private function makeAgent(): Agent
    {
        return Agent::factory()->create([
            'team_id' => $this->team->id,
            'config' => ['reasoning_effort' => 'high'],
        ]);
    }

    public function test_update_stores_normalised_rules_without_touching_other_keys(): void
    {
        $agent = $this->makeAgent();

        $response = app(AgentUpdateTool::class)->handle(new Request([
            'agent_id' => $agent->id,
            'command_rules' => [
                ['prefix' => 'npm publish', 'action' => 'block'],
                ['prefix' => '  git   push  ', 'action' => 'ask'],
            ],
        ]));

        $this->assertFalse($response->isError());
        $this->assertSame([
            'reasoning_effort' => 'high',
            'command_rules' => [
                ['prefix' => 'npm publish', 'action' => 'block'],
                ['prefix' => 'git push', 'action' => 'ask'],
            ],
        ], $agent->fresh()->config);
    }

    public function test_null_removes_the_key_and_empty_list_stores_no_rules(): void
    {
        $agent = $this->makeAgent();

        app(AgentUpdateTool::class)->handle(new Request([
            'agent_id' => $agent->id,
            'command_rules' => [],
        ]));
        $this->assertSame(['reasoning_effort' => 'high', 'command_rules' => []], $agent->fresh()->config);

        $response = app(AgentUpdateTool::class)->handle(new Request([
            'agent_id' => $agent->id,
            'command_rules' => null,
        ]));
        $this->assertFalse($response->isError());
        $this->assertSame(['reasoning_effort' => 'high'], $agent->fresh()->config);
    }

    public function test_prefix_with_an_operator_is_rejected_and_nothing_is_stored(): void
    {
        $agent = $this->makeAgent();

        $response = app(AgentUpdateTool::class)->handle(new Request([
            'agent_id' => $agent->id,
            'command_rules' => [['prefix' => 'git push; rm', 'action' => 'ask']],
        ]));

        $this->assertTrue($response->isError());
        $this->assertSame(['reasoning_effort' => 'high'], $agent->fresh()->config);
    }

    public function test_unknown_action_fails_validation(): void
    {
        $agent = $this->makeAgent();

        $this->expectException(ValidationException::class);
        try {
            app(AgentUpdateTool::class)->handle(new Request([
                'agent_id' => $agent->id,
                'command_rules' => [['prefix' => 'git push', 'action' => 'maybe']],
            ]));
        } finally {
            $this->assertSame(['reasoning_effort' => 'high'], $agent->fresh()->config);
        }
    }
}
