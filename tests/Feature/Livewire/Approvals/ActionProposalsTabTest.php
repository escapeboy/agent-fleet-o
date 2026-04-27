<?php

namespace Tests\Feature\Livewire\Approvals;

use App\Domain\Approval\Actions\CreateActionProposalAction;
use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Shared\Models\Team;
use App\Livewire\Approvals\ApprovalInboxPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ActionProposalsTabTest extends TestCase
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
    }

    public function test_view_switch_to_actions_lists_pending_proposals(): void
    {
        $proposal = app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'tool_call',
            targetId: null,
            summary: 'Test prop',
            payload: ['tool' => 'noop'],
            userId: $this->user->id,
        );

        Livewire::test(ApprovalInboxPage::class)
            ->set('activeView', 'actions')
            ->assertSee('Test prop')
            ->assertSee('tool_call');
    }

    public function test_approve_proposal_method_sets_status_approved(): void
    {
        // Auto-execute job runs after approval — fake queue so this test
        // only asserts the approval state transition.
        Queue::fake();

        $proposal = app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'tool_call',
            targetId: null,
            summary: 'Approve me',
            payload: ['tool' => 'noop'],
            userId: $this->user->id,
        );

        Livewire::test(ApprovalInboxPage::class)
            ->set('activeView', 'actions')
            ->call('approveProposal', $proposal->id)
            ->assertSet('expandedProposalId', null);

        $this->assertSame(ActionProposalStatus::Approved, $proposal->fresh()->status);
    }

    public function test_reject_proposal_flow_requires_reason(): void
    {
        $proposal = app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'tool_call',
            targetId: null,
            summary: 'Reject me',
            payload: ['tool' => 'noop'],
            userId: $this->user->id,
        );

        Livewire::test(ApprovalInboxPage::class)
            ->set('activeView', 'actions')
            ->call('openProposalReject', $proposal->id)
            ->set('proposalRejectionReason', 'too risky')
            ->call('confirmProposalReject');

        $proposal->refresh();
        $this->assertSame(ActionProposalStatus::Rejected, $proposal->status);
        $this->assertSame('too risky', $proposal->decision_reason);
    }

    public function test_toggle_proposal_expands_and_collapses(): void
    {
        $proposal = app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'tool_call',
            targetId: null,
            summary: 'Toggle',
            payload: [],
            userId: $this->user->id,
        );

        Livewire::test(ApprovalInboxPage::class)
            ->set('activeView', 'actions')
            ->call('toggleProposal', $proposal->id)
            ->assertSet('expandedProposalId', $proposal->id)
            ->call('toggleProposal', $proposal->id)
            ->assertSet('expandedProposalId', null);
    }
}
