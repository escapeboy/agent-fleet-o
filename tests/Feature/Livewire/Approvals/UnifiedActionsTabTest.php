<?php

namespace Tests\Feature\Livewire\Approvals;

use App\Domain\Approval\Actions\CreateActionProposalAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\Team;
use App\Livewire\Approvals\ApprovalInboxPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sprint 3d: read-side union of ActionProposals + outbound ApprovalRequests
 * in the "Real-World Actions" view of the Approval Inbox. No bridge tables,
 * no shadow rows; the partial dispatches by source flag.
 */
class UnifiedActionsTabTest extends TestCase
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

    public function test_actions_view_shows_both_action_proposal_and_outbound_approval(): void
    {
        $proposal = app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'tool_call',
            targetId: null,
            summary: 'Delete agent abc',
            payload: ['tool' => 'agent_delete', 'positional_args' => ['abc']],
            userId: $this->user->id,
        );

        $outbound = OutboundProposal::factory()->create([
            'team_id' => $this->team->id,
            'content' => ['subject' => 'Hello', 'body' => 'Test message'],
            'target' => ['email' => 'lead@example.com'],
        ]);

        $approval = ApprovalRequest::create([
            'team_id' => $this->team->id,
            'outbound_proposal_id' => $outbound->id,
            'status' => ApprovalStatus::Pending,
        ]);

        Livewire::withQueryParams(['activeView' => 'actions'])
            ->test(ApprovalInboxPage::class)
            ->assertSee('Delete agent abc')         // ActionProposal
            ->assertSee('Hello')                    // outbound subject in preview
            ->assertSee('lead@example.com')         // outbound target in preview
            ->assertSee('outbound');                // source chip
    }

    public function test_actions_view_pending_count_includes_both_sources(): void
    {
        app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'tool_call',
            targetId: null,
            summary: 'P',
            payload: [],
            userId: $this->user->id,
        );

        // experiment_id is required for outbound ApproveAction joins; even
        // when not approving, factories assert FK presence.
        $experiment = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'status' => ExperimentStatus::AwaitingApproval,
        ]);
        $outbound = OutboundProposal::factory()->create(['team_id' => $this->team->id, 'experiment_id' => $experiment->id]);
        ApprovalRequest::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'outbound_proposal_id' => $outbound->id,
            'status' => ApprovalStatus::Pending,
        ]);
        ApprovalRequest::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'outbound_proposal_id' => OutboundProposal::factory()->create(['team_id' => $this->team->id, 'experiment_id' => $experiment->id])->id,
            'status' => ApprovalStatus::Pending,
        ]);

        // 1 action proposal + 2 outbound approvals = 3 pending in unified count.
        Livewire::withQueryParams(['activeView' => 'actions'])
            ->test(ApprovalInboxPage::class)
            ->assertViewHas('proposalCounts', fn (array $counts) => $counts['pending'] === 3);
    }

    public function test_approve_outbound_from_unified_view_calls_existing_approval_action(): void
    {
        Queue::fake();

        // ApproveAction looks up the experiment to bulk-update sibling outbound
        // proposals — outbound approvals are experiment-scoped in the existing
        // domain model.
        $experiment = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'status' => ExperimentStatus::AwaitingApproval,
        ]);
        $outbound = OutboundProposal::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
        ]);

        $approval = ApprovalRequest::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'outbound_proposal_id' => $outbound->id,
            'status' => ApprovalStatus::Pending,
        ]);

        Livewire::withQueryParams(['activeView' => 'actions'])
            ->test(ApprovalInboxPage::class)
            ->call('approve', $approval->id);

        $approval->refresh();
        $this->assertSame(ApprovalStatus::Approved, $approval->status);
    }

    public function test_actions_view_filters_outbound_by_status_tab(): void
    {
        $outboundApproved = OutboundProposal::factory()->create([
            'team_id' => $this->team->id,
            'content' => ['subject' => 'APPROVED-SUBJECT', 'body' => 'old'],
            'target' => ['email' => 'approved@example.com'],
        ]);
        ApprovalRequest::create([
            'team_id' => $this->team->id,
            'outbound_proposal_id' => $outboundApproved->id,
            'status' => ApprovalStatus::Approved,
        ]);

        $outboundPending = OutboundProposal::factory()->create([
            'team_id' => $this->team->id,
            'content' => ['subject' => 'PENDING-SUBJECT', 'body' => 'new'],
            'target' => ['email' => 'pending@example.com'],
        ]);
        ApprovalRequest::create([
            'team_id' => $this->team->id,
            'outbound_proposal_id' => $outboundPending->id,
            'status' => ApprovalStatus::Pending,
        ]);

        Livewire::withQueryParams(['activeView' => 'actions', 'statusTab' => 'pending'])
            ->test(ApprovalInboxPage::class)
            ->assertSee('PENDING-SUBJECT')
            ->assertDontSee('APPROVED-SUBJECT');
    }
}
