<div wire:poll.10s>
    @if($blocks->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center">
            <p class="text-sm text-gray-400">No execution events yet.</p>
        </div>
    @else
        {{-- Panel header --}}
        <div class="mb-3 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <i class="fa-regular fa-clipboard text-lg text-gray-400"></i>
                <span class="text-sm font-medium text-gray-700">Execution Log</span>
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">{{ $blocks->count() }} {{ Str::plural('block', $blocks->count()) }}</span>
            </div>

            @if($hasFailedBlock)
                <button
                    onclick="document.querySelector('[data-failed-block]')?.scrollIntoView({ behavior: 'smooth', block: 'start' })"
                    class="inline-flex items-center gap-1 rounded-md bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-200 hover:bg-red-100">
                    <i class="fa-solid fa-triangle-exclamation text-xs"></i>
                    Jump to failed
                </button>
            @endif
        </div>

        {{-- Blocks --}}
        <div class="space-y-3">
            @foreach($blocks as $block)
                @php
                    $isStage = $block['type'] === 'stage';
                    $stage = $block['stage'];
                    $statusValue = $isStage ? $stage->status->value : null;
                    $isFailed = $isStage && $statusValue === 'failed';
                    $isRunning = $isStage && $statusValue === 'running';
                    $isCompleted = $isStage && $statusValue === 'completed';

                    $borderClass = $isFailed
                        ? 'border-red-300 ring-1 ring-red-200'
                        : ($isRunning ? 'border-blue-200' : 'border-gray-200');

                    $stageLabel = $isStage
                        ? ucfirst(str_replace('_', ' ', $stage->stage->value))
                        : $block['label'];
                @endphp

                <div
                    class="overflow-hidden rounded-lg border bg-white {{ $borderClass }}"
                    {{ $isFailed ? 'data-failed-block' : '' }}
                    x-data="{
                        open: {{ ($isFailed || $isRunning || ! $isStage) ? 'true' : 'false' }},
                        init() {
                            @if($isRunning)
                            this.$el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                            @endif
                        }
                    }">

                    {{-- Block header --}}
                    <button
                        @click="open = !open"
                        class="flex w-full items-center justify-between px-4 py-3 text-left transition {{ $isFailed ? 'bg-red-50 hover:bg-red-100' : ($isRunning ? 'bg-blue-50 hover:bg-blue-100' : 'hover:bg-gray-50') }}">

                        <div class="flex min-w-0 items-center gap-3">
                            {{-- Status indicator --}}
                            @if(! $isStage)
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100">
                                    <i class="fa-regular fa-clock text-sm text-gray-500"></i>
                                </span>
                            @elseif($isRunning)
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-100">
                                    <i class="fa-solid fa-spinner fa-spin text-sm text-blue-600"></i>
                                </span>
                            @elseif($isCompleted)
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-100">
                                    <i class="fa-solid fa-check text-sm text-green-600"></i>
                                </span>
                            @elseif($isFailed)
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-red-100">
                                    <i class="fa-solid fa-xmark text-sm text-red-600"></i>
                                </span>
                            @else
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100">
                                    <i class="fa-regular fa-clock text-sm text-gray-400"></i>
                                </span>
                            @endif

                            {{-- Stage name + type hint --}}
                            <div class="min-w-0">
                                <span class="truncate text-sm font-medium text-gray-900">{{ $stageLabel }}</span>
                                @if($isStage && $stage->iteration > 1)
                                    <span class="ml-1 text-xs text-gray-400">#{{ $stage->iteration }}</span>
                                @endif
                            </div>

                            {{-- Status badge --}}
                            @if($isStage)
                                @php
                                    $badgeClass = match($statusValue) {
                                        'running' => 'bg-blue-100 text-blue-700',
                                        'completed' => 'bg-green-100 text-green-700',
                                        'failed' => 'bg-red-100 text-red-700',
                                        default => 'bg-gray-100 text-gray-600',
                                    };
                                @endphp
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $badgeClass }}">
                                    {{ strtoupper($statusValue) }}
                                    @if($isRunning)
                                        <span class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-blue-500 align-middle"></span>
                                    @endif
                                </span>
                            @endif
                        </div>

                        {{-- Right side: summary stats + chevron --}}
                        <div class="flex shrink-0 items-center gap-3 pl-3">
                            @if($block['summary']['duration_seconds'])
                                <span class="text-xs text-gray-400">{{ $block['summary']['duration_seconds'] }}s</span>
                            @endif

                            @if($block['summary']['tokens'] > 0)
                                <span class="text-xs text-gray-400">{{ number_format($block['summary']['tokens']) }} tok</span>
                            @endif

                            @if($block['summary']['cost'] > 0)
                                <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-700">
                                    {{ $block['summary']['cost'] }} cr
                                </span>
                            @endif

                            <i class="fa-solid fa-chevron-down text-base shrink-0 text-gray-400 transition-transform duration-150"
                                :class="open ? 'rotate-180' : ''"></i>
                        </div>
                    </button>

                    {{-- Block body --}}
                    <div x-show="open" x-transition.opacity class="divide-y divide-gray-100 border-t border-gray-100">

                        {{-- Failed error message --}}
                        @if($isFailed)
                            @php
                                $failedStep = $block['steps']->firstWhere('status', 'failed');
                                $errorMsg = $failedStep?->error_message ?? ($stage->output_snapshot['error'] ?? null);
                            @endphp
                            @if($errorMsg)
                                <div class="bg-red-50 px-4 py-3">
                                    <p class="text-xs font-medium text-red-700">Error</p>
                                    <p class="mt-1 text-xs text-red-600">{{ $errorMsg }}</p>
                                </div>
                            @endif
                        @endif

                        {{-- State transitions --}}
                        @foreach($block['transitions'] as $transition)
                            <div class="flex items-center gap-3 px-4 py-2">
                                <span class="shrink-0 rounded bg-purple-100 px-1.5 py-0.5 text-[10px] font-semibold text-purple-700">STATE</span>
                                <span class="min-w-0 grow text-xs text-gray-700">{{ $transition->from_state }} → {{ $transition->to_state }}</span>
                                @if($transition->reason)
                                    <span class="hidden truncate text-[10px] text-gray-400 sm:block" title="{{ $transition->reason }}">{{ Str::limit($transition->reason, 40) }}</span>
                                @endif
                                <span class="shrink-0 text-[10px] text-gray-400">{{ $transition->created_at->format('H:i:s') }}</span>
                            </div>
                        @endforeach

                        {{-- Playbook steps --}}
                        @foreach($block['steps'] as $step)
                            @php
                                $stepColor = match($step->status) {
                                    'completed' => 'text-green-600',
                                    'failed' => 'text-red-600',
                                    'running' => 'text-blue-600',
                                    default => 'text-gray-400',
                                };
                                $stepDot = match($step->status) {
                                    'completed' => 'bg-green-500',
                                    'failed' => 'bg-red-500',
                                    'running' => 'bg-blue-500 animate-pulse',
                                    default => 'bg-gray-300',
                                };
                            @endphp
                            <div class="flex items-start gap-3 px-4 py-2.5 {{ $step->status === 'failed' ? 'bg-red-50' : '' }}">
                                <span class="mt-1 h-2 w-2 shrink-0 rounded-full {{ $stepDot }}"></span>
                                <div class="min-w-0 grow">
                                    <div class="flex items-center gap-2">
                                        <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">STEP #{{ $step->order }}</span>
                                        <span class="truncate text-xs text-gray-700">{{ $step->agent?->name ?? 'Unknown agent' }}</span>
                                        @if($step->status === 'running')
                                            <span class="text-[10px] text-blue-500">running…</span>
                                        @endif
                                    </div>
                                    @if($step->error_message)
                                        <p class="mt-1 text-xs text-red-600">{{ $step->error_message }}</p>
                                    @elseif($step->duration_ms)
                                        <p class="mt-0.5 text-[10px] text-gray-400">{{ round($step->duration_ms / 1000, 1) }}s &middot; {{ $step->cost_credits ?? 0 }} credits</p>
                                    @endif
                                </div>
                                <span class="shrink-0 text-[10px] text-gray-400">{{ $step->started_at?->format('H:i:s') }}</span>
                            </div>
                        @endforeach

                        {{-- LLM calls --}}
                        @foreach($block['llmCalls'] as $llmCall)
                            @php
                                $llmColor = match($llmCall->status) {
                                    'completed', 'success' => 'bg-green-100 text-green-700',
                                    'failed', 'error' => 'bg-red-100 text-red-700',
                                    default => 'bg-gray-100 text-gray-600',
                                };
                            @endphp
                            <div class="flex items-center gap-3 px-4 py-2">
                                <span class="shrink-0 rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">LLM</span>
                                <span class="min-w-0 grow truncate text-xs text-gray-700">{{ $llmCall->provider }}/{{ $llmCall->model }}</span>
                                <div class="flex shrink-0 items-center gap-2 text-[10px] text-gray-400">
                                    @if($llmCall->latency_ms)
                                        <span>{{ $llmCall->latency_ms }}ms</span>
                                    @endif
                                    @if($llmCall->input_tokens || $llmCall->output_tokens)
                                        <span>{{ $llmCall->input_tokens ?? 0 }}↑ {{ $llmCall->output_tokens ?? 0 }}↓</span>
                                    @endif
                                    @if($llmCall->cost_credits)
                                        <span class="rounded bg-emerald-50 px-1 text-emerald-700">{{ $llmCall->cost_credits }}cr</span>
                                    @endif
                                    <span>{{ $llmCall->created_at->format('H:i:s') }}</span>
                                </div>
                                @if($llmCall->error)
                                    <span class="ml-1 truncate text-[10px] text-red-500" title="{{ $llmCall->error }}">{{ Str::limit($llmCall->error, 30) }}</span>
                                @endif
                            </div>
                        @endforeach

                        {{-- Approvals --}}
                        @foreach($block['approvals'] as $approval)
                            @php $approvalStatus = $approval->status->value; @endphp
                            <div class="px-4 py-3 {{ $approvalStatus === 'pending' ? 'bg-amber-50' : 'bg-white' }}">
                                <div class="flex gap-3">
                                    <div class="mt-0.5 flex-shrink-0">
                                        @if($approvalStatus === 'pending')
                                            <i class="fa-regular fa-clock text-base text-amber-500"></i>
                                        @elseif($approvalStatus === 'approved')
                                            <i class="fa-solid fa-circle-check text-base text-green-500"></i>
                                        @else
                                            <i class="fa-solid fa-circle-xmark text-base text-red-400"></i>
                                        @endif
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">APPROVAL</span>
                                            <span class="text-[10px] text-gray-400">{{ $approval->created_at->format('H:i:s') }}</span>
                                        </div>

                                        <p class="mt-1 text-xs font-medium {{ $approvalStatus === 'pending' ? 'text-amber-800' : 'text-gray-700' }}">
                                            {{ Str::limit($approval->context['message'] ?? 'Human review needed', 80) }}
                                        </p>

                                        @if($approvalStatus === 'pending')
                                            <div class="mt-2 flex gap-2">
                                                <button
                                                    wire:click="approveInline('{{ $approval->id }}')"
                                                    wire:loading.attr="disabled"
                                                    class="inline-flex items-center rounded-md bg-green-600 px-3 py-1 text-xs font-medium text-white transition-colors hover:bg-green-700 disabled:opacity-50">
                                                    <i class="fa-solid fa-check mr-1 text-xs"></i>
                                                    Approve
                                                </button>
                                                <button
                                                    wire:click="openRejectModal('{{ $approval->id }}')"
                                                    class="inline-flex items-center rounded-md border border-red-300 bg-white px-3 py-1 text-xs font-medium text-red-600 transition-colors hover:bg-red-50">
                                                    Reject
                                                </button>
                                            </div>
                                        @else
                                            <p class="mt-1 text-xs text-gray-400">
                                                {{ ucfirst($approvalStatus) }} by {{ $approval->reviewer?->name ?? 'system' }}
                                                @if($approval->reviewed_at)
                                                    &middot; {{ \Carbon\Carbon::parse($approval->reviewed_at)->diffForHumans() }}
                                                @endif
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach

                        {{-- Empty block body --}}
                        @if($block['transitions']->isEmpty() && $block['steps']->isEmpty() && $block['llmCalls']->isEmpty() && $block['approvals']->isEmpty())
                            <div class="px-4 py-3 text-xs text-gray-400">No events in this stage.</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Inline rejection reason modal --}}
    @if($rejectingApprovalId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" wire:click.self="$set('rejectingApprovalId', '')">
            <div class="mx-4 w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h3 class="mb-3 text-base font-semibold text-gray-900">Reject Approval</h3>
                <textarea
                    wire:model="rejectReason"
                    rows="3"
                    placeholder="Reason for rejection (required, min 10 chars)..."
                    class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-red-400 focus:ring-red-400"></textarea>
                @error('rejectReason')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
                <div class="mt-4 flex justify-end gap-2">
                    <button
                        wire:click="$set('rejectingApprovalId', '')"
                        class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">
                        Cancel
                    </button>
                    <button
                        wire:click="confirmReject"
                        wire:loading.attr="disabled"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50">
                        Confirm Reject
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
