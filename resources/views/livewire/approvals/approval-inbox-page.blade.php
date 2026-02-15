<div>
    {{-- Flash message --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    {{-- Status Tabs --}}
    <div class="mb-6 border-b border-gray-200">
        <nav class="-mb-px flex gap-6">
            @foreach(['pending', 'approved', 'rejected', 'expired'] as $tab)
                <button wire:click="$set('statusTab', '{{ $tab }}')"
                    class="border-b-2 px-1 pb-3 text-sm font-medium transition
                    {{ $statusTab === $tab ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                    {{ ucfirst($tab) }}
                    <span class="ml-1 rounded-full {{ $statusTab === $tab ? 'bg-primary-100 text-primary-700' : 'bg-gray-100 text-gray-600' }} px-2 py-0.5 text-xs">{{ $counts[$tab] }}</span>
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Rejection Modal --}}
    @if($rejectingId)
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-sm font-medium text-red-800">Reject this approval?</p>
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

    {{-- Approval Cards --}}
    <div class="space-y-4">
        @forelse($approvals as $approval)
            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <div class="flex items-start justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-3">
                            <a href="{{ route('experiments.show', $approval->experiment) }}"
                                class="text-base font-medium text-primary-600 hover:text-primary-800">
                                {{ $approval->experiment->title }}
                            </a>
                            <x-status-badge :status="$approval->status->value" />
                            @if($approval->isHumanTask())
                                <span class="rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700">Human Task</span>
                            @endif
                        </div>

                        @if($approval->outboundProposal)
                            <div class="mt-3 rounded-lg bg-gray-50 p-3">
                                <div class="flex items-center gap-2 text-xs text-gray-500">
                                    <span class="font-medium">{{ ucfirst($approval->outboundProposal->channel->value) }}</span>
                                    <span>&middot;</span>
                                    <span>Risk: {{ number_format($approval->outboundProposal->risk_score, 2) }}</span>
                                </div>
                                @if($approval->outboundProposal->content)
                                    <div class="mt-2 max-h-32 overflow-auto text-xs text-gray-600">
                                        <pre class="whitespace-pre-wrap">{{ is_string($approval->outboundProposal->content) ? $approval->outboundProposal->content : json_encode($approval->outboundProposal->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if($approval->isHumanTask())
                            <div class="mt-3">
                                <livewire:approvals.human-task-form :task="$approval" :key="'htf-'.$approval->id" />
                            </div>
                        @elseif($approval->context)
                            <div class="mt-2 text-xs text-gray-500">
                                @if(isset($approval->context['proposal_count']))
                                    <span>{{ $approval->context['proposal_count'] }} proposal(s)</span>
                                @endif
                                @if(isset($approval->context['channels']))
                                    <span class="ml-2">Channels: {{ implode(', ', $approval->context['channels']) }}</span>
                                @endif
                            </div>
                        @endif

                        <div class="mt-2 flex items-center gap-4 text-xs text-gray-500">
                            <span>Expires {{ $approval->expires_at?->diffForHumans() ?? 'never' }}</span>
                            @if($approval->reviewer)
                                <span>Reviewed by {{ $approval->reviewer->name }}</span>
                            @endif
                            @if($approval->rejection_reason)
                                <span class="text-red-600">Reason: {{ $approval->rejection_reason }}</span>
                            @endif
                        </div>
                    </div>

                    @if($approval->status === \App\Domain\Approval\Enums\ApprovalStatus::Pending)
                        <div class="ml-4 flex shrink-0 gap-2">
                            <button wire:click="approve('{{ $approval->id }}')"
                                class="rounded-lg bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700">
                                Approve
                            </button>
                            <button wire:click="openRejectModal('{{ $approval->id }}')"
                                class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">
                                Reject
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-gray-200 bg-white p-12 text-center">
                <p class="text-sm text-gray-400">No {{ $statusTab }} approvals.</p>
            </div>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $approvals->links() }}
    </div>
</div>
