<?php

namespace Tests\Feature\Mcp\Tools;

use App\Domain\Approval\Actions\CreateActionProposalAction;
use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Approval\ActionProposalApproveTool;
use App\Mcp\Tools\Approval\ActionProposalGetTool;
use App\Mcp\Tools\Approval\ActionProposalListTool;
use App\Mcp\Tools\Approval\ActionProposalRejectTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $proposal = $this->makeProposal();

        $payload = $this->callTool(ActionProposalApproveTool::class, [
            'proposal_id' => $proposal->id,
            'reason' => 'looks fine',
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('approved', $payload['status']);
        $this->assertSame(ActionProposalStatus::Approved->value, $proposal->fresh()->status->value);
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

    private function makeProposal(): \App\Domain\Approval\Models\ActionProposal
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
