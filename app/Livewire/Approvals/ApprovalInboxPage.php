<?php

namespace App\Livewire\Approvals;

use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Actions\RejectAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ApprovalInboxPage extends Component
{
    use WithPagination;

    #[Url]
    public string $statusTab = 'pending';

    public ?string $rejectingId = null;

    public string $rejectionReason = '';

    public function approve(string $approvalId): void
    {
        $approval = ApprovalRequest::findOrFail($approvalId);

        $action = app(ApproveAction::class);
        $action->execute($approval, auth()->id());

        session()->flash('message', 'Approved successfully.');
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

    public function render()
    {
        $query = ApprovalRequest::with(['experiment', 'outboundProposal', 'reviewer'])
            ->where('status', $this->statusTab)
            ->latest();

        $counts = [
            'pending' => ApprovalRequest::where('status', ApprovalStatus::Pending)->count(),
            'approved' => ApprovalRequest::where('status', ApprovalStatus::Approved)->count(),
            'rejected' => ApprovalRequest::where('status', ApprovalStatus::Rejected)->count(),
            'expired' => ApprovalRequest::where('status', ApprovalStatus::Expired)->count(),
        ];

        return view('livewire.approvals.approval-inbox-page', [
            'approvals' => $query->paginate(20),
            'counts' => $counts,
        ])->layout('layouts.app', ['header' => 'Approval Inbox']);
    }
}
