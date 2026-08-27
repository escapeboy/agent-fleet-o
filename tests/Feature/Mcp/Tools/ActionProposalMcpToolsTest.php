<?php

namespace Tests\Feature\Mcp\Tools;

use App\Domain\Agent\Models\Agent;
use App\Domain\Approval\Actions\CreateActionProposalAction;
use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Approval\ActionProposalApproveTool;
use App\Mcp\Tools\Approval\ActionProposalGetTool;
use App\Mcp\Tools\Approval\ActionProposalListTool;
use App\Mcp\Tools\Approval\ActionProposalRejectTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Request;
use Tests\TestCase;

class ActionProposalMcpToolsTest extends TestCase
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
    }

    public function test_list_returns_pending_proposals_for_team_only(): void
    {
        $own = $this->makeProposal();

        $other = Team::create([
            'name' => 'Other '.bin2hex(random_bytes(3)),
            'slug' => 'other-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        app(CreateActionProposalAction::class)->execute(
            teamId: $other->id,
            targetType: 'tool_call',
            targetId: null,
            summary: 'Foreign action',
            payload: [],
        );

        $payload = $this->callTool(ActionProposalListTool::class, []);
        $this->assertSame(1, $payload['count']);
        $this->assertSame($own->id, $payload['proposals'][0]['id']);
    }

    public function test_get_returns_full_proposal_with_lineage(): void
    {
        $proposal = $this->makeProposal();
        $proposal->update(['lineage' => [['kind' => 'note', 'snippet' => 'hi']]]);

        $payload = $this->callTool(ActionProposalGetTool::class, ['proposal_id' => $proposal->id]);

        $this->assertSame($proposal->id, $payload['id']);
        $this->assertSame('hi', $payload['lineage'][0]['snippet']);
    }

    public function test_approve_marks_status_approved(): void
    {
        // Auto-execute job is fired after approval; isolate this test from
        // the executor's downstream effects.
        Queue::fake();

        $proposal = $this->makeProposal();

        // A proposal is approved by someone OTHER than whoever raised it —
        // the MCP surface refuses self-approval (see the guard test below).
        $this->actingAs($this->secondTeamMember());

        $payload = $this->callTool(ActionProposalApproveTool::class, [
            'proposal_id' => $proposal->id,
            'reason' => 'looks fine',
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('approved', $payload['status']);
        $this->assertSame(ActionProposalStatus::Approved->value, $proposal->fresh()->status->value);
    }

    public function test_approve_is_refused_when_the_approver_raised_the_proposal(): void
    {
        Queue::fake();

        // ActionProposal gates an agent's own side effects. Over MCP the
        // proposer and the caller are routinely the same identity (stdio runs
        // as the team owner), so allowing this would let an agent wave through
        // its own gated action in the very next tool call.
        $proposal = $this->makeProposal();

        $payload = $this->callTool(ActionProposalApproveTool::class, [
            'proposal_id' => $proposal->id,
            'reason' => 'approving my own request',
        ]);

        $this->assertArrayNotHasKey('success', $payload);
        $this->assertSame(
            ActionProposalStatus::Pending->value,
            $proposal->fresh()->status->value,
            'A self-approved proposal must stay pending.',
        );
    }

    public function test_approve_is_refused_for_an_agent_raised_proposal(): void
    {
        Queue::fake();

        // ToolCallGovernor raises proposals with actor_agent_id and NO
        // actor_user_id, so the identity check cannot catch them — yet this is
        // precisely the machine-approving-machine case the gate exists for.
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $proposal = app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'tool_call',
            targetId: null,
            summary: 'Agent-raised action',
            payload: [],
            agentId: $agent->id,
        );

        $this->assertNull($proposal->actor_user_id, 'Precondition: no user on an agent-raised proposal.');

        // Approve as a DIFFERENT user, so only the agent-origin rule can refuse it.
        $this->actingAs($this->secondTeamMember());

        $payload = $this->callTool(ActionProposalApproveTool::class, [
            'proposal_id' => $proposal->id,
            'reason' => 'rubber stamp',
        ]);

        $this->assertArrayNotHasKey('success', $payload);
        $this->assertSame(
            ActionProposalStatus::Pending->value,
            $proposal->fresh()->status->value,
            'An agent-raised proposal must not be approvable over MCP.',
        );
    }

    private function secondTeamMember(): User
    {
        $other = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($other, ['role' => 'admin']);

        return $other;
    }

    public function test_reject_requires_reason_and_marks_rejected(): void
    {
        $proposal = $this->makeProposal();

        $payload = $this->callTool(ActionProposalRejectTool::class, [
            'proposal_id' => $proposal->id,
            'reason' => 'unsafe',
        ]);

        $this->assertSame('rejected', $payload['status']);
        $this->assertSame(ActionProposalStatus::Rejected->value, $proposal->fresh()->status->value);
    }

    private function makeProposal(): ActionProposal
    {
        return app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'tool_call',
            targetId: null,
            summary: 'Test proposal',
            payload: ['tool' => 'noop'],
            userId: $this->user->id,
        );
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
