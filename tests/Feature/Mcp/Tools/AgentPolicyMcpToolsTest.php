<?php

namespace Tests\Feature\Mcp\Tools;

use App\Domain\Agent\Actions\CreateAgentPolicyAction;
use App\Domain\Approval\Actions\CreateActionProposalAction;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Agent\AgentPolicyCreateTool;
use App\Mcp\Tools\Agent\AgentPolicyGetTool;
use App\Mcp\Tools\Agent\AgentPolicyListTool;
use App\Mcp\Tools\Agent\AgentPolicyRollbackTool;
use App\Mcp\Tools\Agent\AgentPolicyUpdateTool;
use App\Mcp\Tools\Approval\ActionProposalExplainTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

class AgentPolicyMcpToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->actingAs($this->user);
        app()->instance('mcp.team_id', $this->team->id);
        config(['agent_policies.enabled' => true]);
    }

    public function test_create_then_get_returns_version_history(): void
    {
        $created = $this->callTool(AgentPolicyCreateTool::class, [
            'name' => 'Default',
            'rules' => json_encode(['risk_ceiling' => 'low']),
            'enabled' => true,
        ]);
        $this->assertTrue($created['success']);

        $got = $this->callTool(AgentPolicyGetTool::class, ['policy_id' => $created['id']]);
        $this->assertSame('low', $got['current_rules']['risk_ceiling']);
        $this->assertCount(1, $got['versions']);
    }

    public function test_update_mints_version_and_rollback_restores(): void
    {
        $created = $this->callTool(AgentPolicyCreateTool::class, [
            'name' => 'P', 'rules' => json_encode(['risk_ceiling' => 'low']),
        ]);
        $get1 = $this->callTool(AgentPolicyGetTool::class, ['policy_id' => $created['id']]);
        $v1Id = $get1['versions'][0]['id'];

        $this->callTool(AgentPolicyUpdateTool::class, [
            'policy_id' => $created['id'],
            'rules' => json_encode(['risk_ceiling' => 'critical']),
        ]);

        $rolled = $this->callTool(AgentPolicyRollbackTool::class, [
            'policy_id' => $created['id'],
            'version_id' => $v1Id,
        ]);
        $this->assertSame(3, $rolled['current_version']);

        $get2 = $this->callTool(AgentPolicyGetTool::class, ['policy_id' => $created['id']]);
        $this->assertSame('low', $get2['current_rules']['risk_ceiling']);
    }

    public function test_list_scopes_to_team(): void
    {
        app(CreateAgentPolicyAction::class)->execute(teamId: $this->team->id, name: 'Mine', enabled: true);

        $other = Team::create([
            'name' => 'O '.bin2hex(random_bytes(3)),
            'slug' => 'o-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        app(CreateAgentPolicyAction::class)->execute(teamId: $other->id, name: 'Theirs', enabled: true);

        $list = $this->callTool(AgentPolicyListTool::class, []);
        $this->assertCount(1, $list['policies']);
        $this->assertSame('Mine', $list['policies'][0]['name']);
    }

    public function test_explain_reports_pinned_policy_and_verdict(): void
    {
        app(CreateAgentPolicyAction::class)->execute(
            teamId: $this->team->id, name: 'P', enabled: true,
            rules: ['denied_target_types' => ['git_push']],
        );
        $proposal = app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id, targetType: 'git_push', targetId: null,
            summary: 'x', payload: [], riskLevel: 'medium',
        );

        $explain = $this->callTool(ActionProposalExplainTool::class, ['proposal_id' => $proposal->id]);

        $this->assertNotNull($explain['policy']);
        $this->assertSame('deny', $explain['policy_decision']['decision']);
        $this->assertSame('rejected', $explain['proposal']['status']);
    }

    /**
     * @param  class-string  $toolClass
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function callTool(string $toolClass, array $args): array
    {
        $tool = app($toolClass);
        $response = $tool->handle(new Request($args));

        return json_decode((string) $response->content(), true);
    }
}
