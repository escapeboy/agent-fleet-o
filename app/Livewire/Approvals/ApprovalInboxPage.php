<?php

namespace App\Livewire\Approvals;

use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Actions\ApproveActionProposalAction;
use App\Domain\Approval\Actions\RejectAction;
use App\Domain\Approval\Actions\RejectActionProposalAction;
use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Approval\Models\ApprovalRequest;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ApprovalInboxPage extends Component
{
    use WithPagination;

    #[Url]
    public string $activeView = 'approvals'; // approvals | actions

    #[Url]
    public string $statusTab = 'pending';

    public ?string $expandedProposalId = null;

    public ?string $rejectingProposalId = null;

    public string $proposalRejectionReason = '';

    public ?string $rejectingId = null;

    public string $rejectionReason = '';

    public ?string $editingChatbotApprovalId = null;

    public string $editedChatbotContent = '';

    public function approve(string $approvalId): void
    {
        $approval = ApprovalRequest::findOrFail($approvalId);

        $action = app(ApproveAction::class);
        $action->execute($approval, auth()->id());

        session()->flash('message', 'Approved successfully.');
    }

    public function startEditChatbotResponse(string $approvalId): void
    {
        $approval = ApprovalRequest::findOrFail($approvalId);
        $this->editingChatbotApprovalId = $approvalId;
        $this->editedChatbotContent = $approval->chatbotMessage?->draft_content ?? '';
    }

    public function approveWithEdit(string $approvalId): void
    {
        $approval = ApprovalRequest::findOrFail($approvalId);
        $approval->update(['edited_content' => $this->editedChatbotContent]);

        $action = app(ApproveAction::class);
        $action->execute($approval, auth()->id());

        $this->editingChatbotApprovalId = null;
        $this->editedChatbotContent = '';

        session()->flash('message', 'Approved with edits successfully.');
    }

    public function cancelEditChatbot(): void
    {
        $this->editingChatbotApprovalId = null;
        $this->editedChatbotContent = '';
    }

    public function openRejectModal(string $approvalId): void
    {
        $this->rejectingId = $approvalId;
        $this->rejectionReason = '';
    }

    public function confirmReject(): void
    {
        if (! $this->rejectingId) {
            return;
        }

        $approval = ApprovalRequest::findOrFail($this->rejectingId);

        $action = app(RejectAction::class);
        $action->execute($approval, auth()->id(), $this->rejectionReason ?: 'Rejected by operator');

        $this->rejectingId = null;
        $this->rejectionReason = '';

        session()->flash('message', 'Rejected successfully.');
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectionReason = '';
    }

    public function toggleProposal(string $proposalId): void
    {
        $this->expandedProposalId = $this->expandedProposalId === $proposalId ? null : $proposalId;
    }

    public function approveProposal(string $proposalId): void
    {
        $proposal = ActionProposal::findOrFail($proposalId);
        app(ApproveActionProposalAction::class)->execute($proposal, auth()->user());
        $this->expandedProposalId = null;
        session()->flash('message', 'Proposal approved.');
    }

    public function openProposalReject(string $proposalId): void
    {
        $this->rejectingProposalId = $proposalId;
        $this->proposalRejectionReason = '';
    }

    public function confirmProposalReject(): void
    {
        if (! $this->rejectingProposalId) {
            return;
        }

        $reason = trim($this->proposalRejectionReason) !== ''
            ? $this->proposalRejectionReason
            : 'Rejected by operator';

        $proposal = ActionProposal::findOrFail($this->rejectingProposalId);
        app(RejectActionProposalAction::class)->execute($proposal, auth()->user(), $reason);

        $this->rejectingProposalId = null;
        $this->proposalRejectionReason = '';
        $this->expandedProposalId = null;
        session()->flash('message', 'Proposal rejected.');
    }

    public function cancelProposalReject(): void
    {
        $this->rejectingProposalId = null;
        $this->proposalRejectionReason = '';
    }

    public function render()
    {
        $query = ApprovalRequest::with(['experiment', 'outboundProposal', 'reviewer', 'worktreeExecution', 'chatbotMessage'])
            ->where('status', $this->statusTab)
            ->latest();

        $counts = [
            'pending' => ApprovalRequest::where('status', ApprovalStatus::Pending)->count(),
            'approved' => ApprovalRequest::where('status', ApprovalStatus::Approved)->count(),
            'rejected' => ApprovalRequest::where('status', ApprovalStatus::Rejected)->count(),
            'expired' => ApprovalRequest::where('status', ApprovalStatus::Expired)->count(),
        ];

        // ActionProposals (the new generalized gate, Sprint 3a/3b/3c).
        $actionProposalsCollection = ActionProposal::with(['actorUser', 'actorAgent', 'decidedByUser'])
            ->where('status', $this->statusTab)
            ->latest()
            ->limit(50)
            ->get();

        // Outbound ApprovalRequests — surface them in the same view so the
        // user gets one unified "Real-World Actions" inbox per Sprint 3d
        // (read-side union; no bridge tables, no shadow rows).
        $outboundApprovals = ApprovalRequest::with(['outboundProposal', 'experiment', 'reviewer'])
            ->whereNotNull('outbound_proposal_id')
            ->where('status', $this->statusTab)
            ->latest()
            ->limit(50)
            ->get();

        // Merge into a single time-sorted collection. Each item carries a
        // `_source` flag that the partial branches on. Manually limited to
        // 20 (sum of two paginators isn't trivial; this view is low-traffic).
        $unifiedActions = $actionProposalsCollection
            ->map(fn ($p) => (object) ['_source' => 'action_proposal', '_at' => $p->created_at, 'item' => $p])
            ->concat(
                $outboundApprovals->map(fn ($a) => (object) ['_source' => 'outbound_approval', '_at' => $a->created_at, 'item' => $a]),
            )
            ->sortByDesc('_at')
            ->values()
            ->take(20);

        $outboundCounts = [
            'pending' => ApprovalRequest::whereNotNull('outbound_proposal_id')->where('status', ApprovalStatus::Pending)->count(),
            'approved' => ApprovalRequest::whereNotNull('outbound_proposal_id')->where('status', ApprovalStatus::Approved)->count(),
            'rejected' => ApprovalRequest::whereNotNull('outbound_proposal_id')->where('status', ApprovalStatus::Rejected)->count(),
            'expired' => ApprovalRequest::whereNotNull('outbound_proposal_id')->where('status', ApprovalStatus::Expired)->count(),
        ];

        $proposalCounts = [
            'pending' => ActionProposal::where('status', ActionProposalStatus::Pending->value)->count() + $outboundCounts['pending'],
            'approved' => ActionProposal::where('status', ActionProposalStatus::Approved->value)->count() + $outboundCounts['approved'],
            'rejected' => ActionProposal::where('status', ActionProposalStatus::Rejected->value)->count() + $outboundCounts['rejected'],
            'expired' => ActionProposal::where('status', ActionProposalStatus::Expired->value)->count() + $outboundCounts['expired'],
        ];

        return view('livewire.approvals.approval-inbox-page', [
            'approvals' => $query->paginate(20),
            'counts' => $counts,
            'unifiedActions' => $unifiedActions,
            'proposalCounts' => $proposalCounts,
        ])->layout('layouts.app', ['header' => 'Approval Inbox']);
    }
}
