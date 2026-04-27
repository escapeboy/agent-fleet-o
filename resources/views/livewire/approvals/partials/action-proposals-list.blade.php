<div>
    {{-- Reject Modal for ActionProposals --}}
    @if($rejectingProposalId)
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-sm font-medium text-red-800">Reject this action proposal?</p>
            <x-form-textarea wire:model="proposalRejectionReason" rows="2" placeholder="Reason for rejection (required)"
                class="mt-2 border-red-300 focus:border-red-500 focus:ring-red-500" />
            <div class="mt-3 flex gap-2">
                <button wire:click="confirmProposalReject" class="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700">
                    Confirm Reject
                </button>
                <button wire:click="cancelProposalReject" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- Reject Modal for Outbound (reuses existing Sprint 1 ApprovalRequest reject flow) --}}
    @if($rejectingId)
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-sm font-medium text-red-800">Reject this outbound action?</p>
            <x-form-textarea wire:model="rejectionReason" rows="2" placeholder="Reason for rejection (optional)"
                class="mt-2 border-red-300 focus:border-red-500 focus:ring-red-500" />
            <div class="mt-3 flex gap-2">
                <button wire:click="confirmReject" class="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700">
                    Confirm Reject
                </button>
                <button wire:click="cancelReject" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    <div class="space-y-3">
        @forelse($unifiedActions as $row)
            @if($row->_source === 'action_proposal')
                @include('livewire.approvals.partials.action-proposal-row', ['p' => $row->item])
            @elseif($row->_source === 'outbound_approval')
                @include('livewire.approvals.partials.outbound-approval-row', ['approval' => $row->item])
            @endif
        @empty
            <div class="rounded-xl border border-gray-200 bg-white p-12 text-center">
                <p class="text-sm text-gray-400">No {{ $statusTab }} actions.</p>
                <p class="mt-1 text-xs text-gray-400">Real-world actions appear here when the assistant proposes a destructive operation, or when an outbound message awaits approval.</p>
            </div>
        @endforelse
    </div>
</div>
