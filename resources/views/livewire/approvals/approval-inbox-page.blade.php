<div>
    {{-- Flash message --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    {{-- View Switcher: Approval Requests vs Real-World Actions --}}
    <div class="mb-4 inline-flex rounded-lg border border-gray-200 bg-white p-0.5">
        <button wire:click="$set('activeView', 'approvals')"
            class="rounded-md px-3 py-1.5 text-sm font-medium transition
            {{ $activeView === 'approvals' ? 'bg-primary-600 text-white' : 'text-gray-600 hover:bg-gray-50' }}">
            <i class="fa-solid fa-check-circle mr-1"></i>
            Approval Requests
            <span class="ml-1 rounded-full {{ $activeView === 'approvals' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-600' }} px-1.5 py-0.5 text-xs">{{ $counts['pending'] }}</span>
        </button>
        <button wire:click="$set('activeView', 'actions')"
            class="rounded-md px-3 py-1.5 text-sm font-medium transition
            {{ $activeView === 'actions' ? 'bg-primary-600 text-white' : 'text-gray-600 hover:bg-gray-50' }}">
            <i class="fa-solid fa-bolt mr-1"></i>
            Real-World Actions
            <span class="ml-1 rounded-full {{ $activeView === 'actions' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-600' }} px-1.5 py-0.5 text-xs">{{ $proposalCounts['pending'] }}</span>
        </button>
    </div>

    {{-- Status Tabs --}}
    <div class="mb-6 border-b border-gray-200">
        <nav class="-mb-px flex gap-6 overflow-x-auto scrollbar-none">
            @php
                $currentCounts = $activeView === 'actions' ? $proposalCounts : $counts;
            @endphp
            @foreach(['pending', 'approved', 'rejected', 'expired'] as $tab)
                <button wire:click="$set('statusTab', '{{ $tab }}')"
                    class="whitespace-nowrap border-b-2 px-1 pb-3 text-sm font-medium transition
                    {{ $statusTab === $tab ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                    {{ ucfirst($tab) }}
                    <span class="ml-1 rounded-full {{ $statusTab === $tab ? 'bg-primary-100 text-primary-700' : 'bg-gray-100 text-gray-600' }} px-2 py-0.5 text-xs">{{ $currentCounts[$tab] }}</span>
                </button>
            @endforeach
        </nav>
    </div>

    @if($activeView === 'actions')
        @include('livewire.approvals.partials.action-proposals-list', ['unifiedActions' => $unifiedActions])
    @else

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
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-3">
                            <a href="{{ route('experiments.show', $approval->experiment) }}"
                                class="text-base font-medium text-primary-600 hover:text-primary-800">
                                {{ $approval->experiment->title }}
                            </a>
                            <x-status-badge :status="$approval->status->value" />
                            @if($approval->isClarification())
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">Clarification</span>
                            @elseif($approval->isHumanTask())
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

                        @if($approval->worktreeExecution)
                            @php $wt = $approval->worktreeExecution @endphp
                            <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3">
                                <div class="flex items-center gap-3 text-xs font-medium text-amber-800">
                                    <span>Code Execution</span>
                                    <span class="font-mono text-gray-600">{{ $wt->branch_name }}</span>
                                    @if($wt->exit_code !== null)
                                        <span class="rounded px-1.5 py-0.5 {{ $wt->exit_code === 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                            exit {{ $wt->exit_code }}
                                        </span>
                                    @endif
                                </div>

                                @if($wt->diff)
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-xs font-medium text-gray-700 hover:text-gray-900">
                                            View diff
                                        </summary>
                                        <div class="mt-2 max-h-64 overflow-auto rounded border border-gray-200 bg-gray-900 p-2 text-xs">
                                            @foreach(explode("\n", $wt->diff) as $line)
                                                @php
                                                    $class = match(true) {
                                                        str_starts_with($line, '+') && !str_starts_with($line, '+++') => 'text-green-400',
                                                        str_starts_with($line, '-') && !str_starts_with($line, '---') => 'text-red-400',
                                                        str_starts_with($line, '@@') => 'text-blue-400',
                                                        str_starts_with($line, 'diff ') || str_starts_with($line, 'index ') => 'text-yellow-400',
                                                        default => 'text-gray-300',
                                                    };
                                                @endphp
                                                <div class="{{ $class }} whitespace-pre font-mono">{{ $line }}</div>
                                            @endforeach
                                        </div>
                                    </details>
                                @else
                                    <p class="mt-1 text-xs text-amber-700">No file changes produced.</p>
                                @endif

                                @if($wt->stdout)
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-xs font-medium text-gray-700 hover:text-gray-900">
                                            stdout
                                        </summary>
                                        <div class="mt-1 max-h-32 overflow-auto rounded bg-gray-100 p-2 font-mono text-xs text-gray-700">
                                            <pre class="whitespace-pre-wrap">{{ $wt->stdout }}</pre>
                                        </div>
                                    </details>
                                @endif

                                @if($wt->stderr)
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-xs font-medium text-red-600 hover:text-red-800">
                                            stderr
                                        </summary>
                                        <div class="mt-1 max-h-32 overflow-auto rounded bg-red-50 p-2 font-mono text-xs text-red-700">
                                            <pre class="whitespace-pre-wrap">{{ $wt->stderr }}</pre>
                                        </div>
                                    </details>
                                @endif
                            </div>
                        @endif

                        {{-- Chatbot Response Approval --}}
                        @if($approval->isChatbotResponse() && $approval->chatbotMessage)
                            @php $ctx = $approval->context ?? []; @endphp
                            <div class="mt-3 rounded-lg border border-blue-200 bg-blue-50 p-4 space-y-3">
                                <div class="flex items-center gap-2">
                                    <span class="rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-700">Chatbot Response</span>
                                    <span class="text-xs text-blue-600">{{ $ctx['chatbot_name'] ?? '' }}</span>
                                    @if(isset($ctx['confidence']))
                                        <span class="rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-700">
                                            Confidence: {{ number_format((float)$ctx['confidence'] * 100, 0) }}%
                                        </span>
                                    @endif
                                </div>

                                {{-- User message --}}
                                <div>
                                    <p class="text-xs font-medium text-gray-500 mb-1">User asked:</p>
                                    <div class="rounded bg-white border border-gray-200 px-3 py-2 text-sm text-gray-800">
                                        {{ $ctx['user_message'] ?? '' }}
                                    </div>
                                </div>

                                {{-- Draft response --}}
                                <div>
                                    <p class="text-xs font-medium text-gray-500 mb-1">AI Draft Response:</p>
                                    <div class="rounded bg-white border border-gray-200 px-3 py-2 text-sm text-gray-800 whitespace-pre-wrap max-h-40 overflow-y-auto">
                                        {{ $approval->chatbotMessage->draft_content ?? '' }}
                                    </div>
                                </div>

                                {{-- Recent conversation context --}}
                                @if(!empty($ctx['recent_messages']))
                                    <details class="text-xs">
                                        <summary class="cursor-pointer font-medium text-gray-500 hover:text-gray-700">Show conversation context ({{ count($ctx['recent_messages']) }} messages)</summary>
                                        <div class="mt-2 space-y-1 max-h-32 overflow-y-auto">
                                            @foreach($ctx['recent_messages'] as $msg)
                                                <div class="flex gap-2">
                                                    <span class="font-medium {{ ($msg['role'] ?? '') === 'user' ? 'text-blue-600' : 'text-gray-600' }}">{{ ucfirst($msg['role'] ?? '') }}:</span>
                                                    <span class="text-gray-700">{{ \Illuminate\Support\Str::limit($msg['content'] ?? '', 120) }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif

                                {{-- Edit & Approve inline form --}}
                                @if($editingChatbotApprovalId === $approval->id)
                                    <div class="space-y-2">
                                        <p class="text-xs font-medium text-gray-700">Edit response before approving:</p>
                                        <x-form-textarea wire:model="editedChatbotContent" rows="4" />
                                        <div class="flex gap-2">
                                            <button wire:click="approveWithEdit('{{ $approval->id }}')"
                                                    class="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-green-700">
                                                Approve with Edits
                                            </button>
                                            <button wire:click="cancelEditChatbot"
                                                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif

                        {{-- Structured preview (code diff, email, JSON diff, API request) --}}
                        @if($approval->preview_type)
                            <x-approval-preview :approval="$approval" />
                        @endif

                        @if($approval->isClarification() || $approval->isHumanTask())
                            <div class="mt-3">
                                <livewire:approvals.human-task-form :task="$approval" :key="'htf-'.$approval->id" />
                            </div>
                        @elseif($approval->context && !$approval->isChatbotResponse())
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

                    @if($approval->status === \App\Domain\Approval\Enums\ApprovalStatus::Pending && ! $approval->isClarification() && ! $approval->isHumanTask())
                        <div class="mt-3 flex shrink-0 flex-row flex-wrap gap-2 sm:ml-4 sm:mt-0 sm:flex-col">
                            <button wire:click="approve('{{ $approval->id }}')"
                                class="rounded-lg bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700">
                                Approve
                            </button>
                            @if($approval->isChatbotResponse())
                                <button wire:click="startEditChatbotResponse('{{ $approval->id }}')"
                                    class="rounded-lg border border-blue-300 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-50">
                                    Edit &amp; Approve
                                </button>
                            @endif
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
    @endif {{-- /activeView === 'approvals' --}}
</div>
