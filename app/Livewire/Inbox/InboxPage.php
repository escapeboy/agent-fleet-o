<?php

declare(strict_types=1);

namespace App\Livewire\Inbox;

use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Actions\RejectAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Livewire\Inbox\DTOs\InboxItemDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class InboxPage extends Component
{
    /** @var 'all'|'approvals'|'human_tasks'|'proposals' */
    public string $filter = 'all';

    public function setFilter(string $filter): void
    {
        if (! in_array($filter, ['all', 'approvals', 'human_tasks', 'proposals'], true)) {
            return;
        }
        $this->filter = $filter;
    }

    public function quickApprove(string $approvalId, ApproveAction $action): void
    {
        Gate::authorize('edit-content');

        $approval = ApprovalRequest::find($approvalId);
        if (! $approval || $approval->status !== ApprovalStatus::Pending) {
            return;
        }

        $action->execute(approvalRequest: $approval, reviewerId: (string) auth()->id());
        session()->flash('message', 'Approved.');
    }

    public function quickReject(string $approvalId, RejectAction $action): void
    {
        Gate::authorize('edit-content');

        $approval = ApprovalRequest::find($approvalId);
        if (! $approval || $approval->status !== ApprovalStatus::Pending) {
            return;
        }

        $action->execute(
            approvalRequest: $approval,
            reviewerId: (string) auth()->id(),
            reason: 'Rejected from inbox.',
        );
        session()->flash('message', 'Rejected.');
    }

    public function render()
    {
        $approvals = $this->fetchApprovals();
        $proposals = $this->fetchProposals();

        $items = $approvals->concat($proposals)
            ->sortByDesc(fn (InboxItemDTO $i) => $i->createdAt?->timestamp ?? 0)
            ->values();

        $filteredItems = match ($this->filter) {
            'approvals' => $items->filter(fn ($i) => $i->kind === 'approval'),
            'human_tasks' => $items->filter(fn ($i) => $i->kind === 'human_task'),
            'proposals' => $items->filter(fn ($i) => $i->kind === 'proposal'),
            default => $items,
        };

        return view('livewire.inbox.inbox-page', [
            'items' => $filteredItems->values(),
            'counts' => [
                'all' => $items->count(),
                'approvals' => $items->filter(fn ($i) => $i->kind === 'approval')->count(),
                'human_tasks' => $items->filter(fn ($i) => $i->kind === 'human_task')->count(),
                'proposals' => $items->filter(fn ($i) => $i->kind === 'proposal')->count(),
            ],
        ])->layout('layouts.app', ['header' => 'Inbox']);
    }

    /** @return Collection<int, InboxItemDTO> */
    private function fetchApprovals(): Collection
    {
        $rows = ApprovalRequest::query()
            ->where('status', ApprovalStatus::Pending)
            ->with(['experiment'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return $rows->map(fn (ApprovalRequest $a) => new InboxItemDTO(
            id: $a->id,
            kind: $a->isHumanTask() ? 'human_task' : 'approval',
            title: $this->approvalTitle($a),
            subtitle: $a->experiment?->title ?? $a->context['summary'] ?? null,
            status: $a->status->value,
            createdAt: $a->created_at,
            slaDeadline: $a->sla_deadline,
            slaState: InboxItemDTO::slaState($a->sla_deadline),
            detailUrl: route('approvals.index'),
        ));
    }

    /** @return Collection<int, InboxItemDTO> */
    private function fetchProposals(): Collection
    {
        $rows = OutboundProposal::query()
            ->where('status', OutboundProposalStatus::PendingApproval)
            ->with(['experiment'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return $rows->map(fn (OutboundProposal $p) => new InboxItemDTO(
            id: $p->id,
            kind: 'proposal',
            title: $p->channel->value.' → '.($p->target['address'] ?? $p->target['url'] ?? 'unknown'),
            subtitle: $p->experiment?->title ?? null,
            status: $p->status->value,
            createdAt: $p->created_at,
            slaDeadline: null,
            slaState: 'none',
            detailUrl: route('approvals.index'),
        ));
    }

    private function approvalTitle(ApprovalRequest $a): string
    {
        if ($a->isHumanTask()) {
            return 'Human task';
        }

        if ($a->isCredentialReview()) {
            return 'Credential review';
        }

        if ($a->isCodeExecution()) {
            return 'Code-execution review';
        }

        if ($a->isSecurityReview()) {
            return 'Security review';
        }

        if ($a->outbound_proposal_id !== null) {
            return 'Outbound approval';
        }

        return 'Approval request';
    }
}
