<?php

declare(strict_types=1);

namespace App\Livewire\Inbox;

use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Actions\RejectAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Inbox\Models\InboxQueue;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Livewire\Inbox\DTOs\InboxItemDTO;
use App\Livewire\Inbox\Services\InboxTriageScorer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Component;

class InboxPage extends Component
{
    /** @var 'all'|'approvals'|'human_tasks'|'proposals' */
    public string $filter = 'all';

    /** @var array<int, string> Approval IDs selected for bulk operations. */
    public array $selectedApprovalIds = [];

    /** Active custom queue UUID, or null for the default unfiltered view. */
    public ?string $activeQueueId = null;

    /** When true, items are sorted by triageScore descending instead of created_at. */
    public bool $sortByTriage = false;

    // Inline create-queue form state
    public bool $showCreateQueue = false;

    public string $newQueueName = '';

    /** @var array<int, string> */
    public array $newQueueKinds = [];

    public function setFilter(string $filter): void
    {
        if (! in_array($filter, ['all', 'approvals', 'human_tasks', 'proposals'], true)) {
            return;
        }
        $this->filter = $filter;
        $this->activeQueueId = null;
        $this->selectedApprovalIds = [];
    }

    public function selectQueue(?string $queueId): void
    {
        $this->activeQueueId = $queueId;
        $this->selectedApprovalIds = [];
    }

    public function startCreateQueue(): void
    {
        $this->showCreateQueue = true;
        $this->newQueueName = '';
        $this->newQueueKinds = [];
    }

    public function cancelCreateQueue(): void
    {
        $this->showCreateQueue = false;
        $this->newQueueName = '';
        $this->newQueueKinds = [];
        $this->resetValidation();
    }

    public function createQueue(): void
    {
        Gate::authorize('edit-content');

        $this->validate([
            'newQueueName' => 'required|string|min:2|max:100',
            'newQueueKinds' => 'array',
            'newQueueKinds.*' => 'in:approval,human_task,proposal',
        ]);

        $queue = InboxQueue::create([
            'team_id' => auth()->user()->current_team_id,
            'user_id' => auth()->id(),
            'name' => $this->newQueueName,
            'slug' => Str::slug($this->newQueueName).'-'.Str::random(6),
            'filter_rules' => ['kinds' => $this->newQueueKinds],
            'sort_order' => InboxQueue::where('team_id', auth()->user()->current_team_id)->count(),
        ]);

        $this->cancelCreateQueue();
        $this->activeQueueId = $queue->id;
        session()->flash('message', 'Queue created.');
    }

    public function deleteQueue(string $queueId): void
    {
        Gate::authorize('edit-content');

        $queue = InboxQueue::find($queueId);
        if (! $queue) {
            return;
        }

        $queue->delete();

        if ($this->activeQueueId === $queueId) {
            $this->activeQueueId = null;
        }

        session()->flash('message', 'Queue deleted.');
    }

    public function toggleSelection(string $approvalId): void
    {
        if (in_array($approvalId, $this->selectedApprovalIds, true)) {
            $this->selectedApprovalIds = array_values(array_diff($this->selectedApprovalIds, [$approvalId]));
        } else {
            $this->selectedApprovalIds[] = $approvalId;
        }
    }

    public function bulkApprove(ApproveAction $action): void
    {
        Gate::authorize('edit-content');

        if ($this->selectedApprovalIds === []) {
            return;
        }

        $count = 0;
        foreach ($this->selectedApprovalIds as $id) {
            $approval = ApprovalRequest::find($id);
            if (! $approval || $approval->status !== ApprovalStatus::Pending) {
                continue;
            }
            try {
                $action->execute(approvalRequest: $approval, reviewerId: (string) auth()->id());
                $count++;
            } catch (\Throwable) {
                // skip failures; continue with batch
            }
        }

        $this->selectedApprovalIds = [];
        session()->flash('message', "Approved {$count} item(s).");
    }

    public function bulkReject(RejectAction $action): void
    {
        Gate::authorize('edit-content');

        if ($this->selectedApprovalIds === []) {
            return;
        }

        $count = 0;
        foreach ($this->selectedApprovalIds as $id) {
            $approval = ApprovalRequest::find($id);
            if (! $approval || $approval->status !== ApprovalStatus::Pending) {
                continue;
            }
            try {
                $action->execute(
                    approvalRequest: $approval,
                    reviewerId: (string) auth()->id(),
                    reason: 'Rejected from inbox (bulk).',
                );
                $count++;
            } catch (\Throwable) {
                // skip
            }
        }

        $this->selectedApprovalIds = [];
        session()->flash('message', "Rejected {$count} item(s).");
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
            ->sortByDesc(
                $this->sortByTriage
                    ? fn (InboxItemDTO $i) => $i->triageScore
                    : fn (InboxItemDTO $i) => $i->createdAt?->timestamp ?? 0,
            )
            ->values();

        $activeQueue = $this->activeQueueId
            ? InboxQueue::find($this->activeQueueId)
            : null;

        if ($activeQueue) {
            $allowed = $activeQueue->allowedKinds();
            if (! empty($allowed)) {
                $items = $items->filter(fn (InboxItemDTO $i) => in_array($i->kind, $allowed, true))->values();
            }
        } else {
            $items = match ($this->filter) {
                'approvals' => $items->filter(fn ($i) => $i->kind === 'approval')->values(),
                'human_tasks' => $items->filter(fn ($i) => $i->kind === 'human_task')->values(),
                'proposals' => $items->filter(fn ($i) => $i->kind === 'proposal')->values(),
                default => $items,
            };
        }

        $allItems = $approvals->concat($proposals);

        return view('livewire.inbox.inbox-page', [
            'items' => $items,
            'queues' => InboxQueue::orderBy('sort_order')->get(),
            'activeQueue' => $activeQueue,
            'counts' => [
                'all' => $allItems->count(),
                'approvals' => $allItems->filter(fn ($i) => $i->kind === 'approval')->count(),
                'human_tasks' => $allItems->filter(fn ($i) => $i->kind === 'human_task')->count(),
                'proposals' => $allItems->filter(fn ($i) => $i->kind === 'proposal')->count(),
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

        $scorer = app(InboxTriageScorer::class);

        return $rows->map(function (ApprovalRequest $a) use ($scorer) {
            $score = $scorer->scoreApproval($a);
            $rec = $scorer->recommendation($score);

            return new InboxItemDTO(
                id: $a->id,
                kind: $a->isHumanTask() ? 'human_task' : 'approval',
                title: $this->approvalTitle($a),
                subtitle: $a->experiment?->title ?? $a->context['summary'] ?? null,
                status: $a->status->value,
                createdAt: $a->created_at,
                slaDeadline: $a->sla_deadline,
                slaState: InboxItemDTO::slaState($a->sla_deadline),
                detailUrl: route('approvals.index'),
                triageScore: $score,
                triageRec: $rec,
                triageLabel: $scorer->recommendationLabel($rec),
                triageColor: $scorer->recommendationColor($rec),
            );
        });
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

        $scorer = app(InboxTriageScorer::class);

        return $rows->map(function (OutboundProposal $p) use ($scorer) {
            $score = $scorer->scoreProposal($p);
            $rec = $scorer->recommendation($score);

            return new InboxItemDTO(
                id: $p->id,
                kind: 'proposal',
                title: $p->channel->value.' → '.($p->target['address'] ?? $p->target['url'] ?? 'unknown'),
                subtitle: $p->experiment?->title ?? null,
                status: $p->status->value,
                createdAt: $p->created_at,
                slaDeadline: null,
                slaState: 'none',
                detailUrl: route('approvals.index'),
                triageScore: $score,
                triageRec: $rec,
                triageLabel: $scorer->recommendationLabel($rec),
                triageColor: $scorer->recommendationColor($rec),
            );
        });
    }

    public function toggleTriageSort(): void
    {
        $this->sortByTriage = ! $this->sortByTriage;
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
