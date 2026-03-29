<div wire:poll.10s>
    @if($events->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center">
            <p class="text-sm text-gray-400">No execution events yet.</p>
        </div>
    @else
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-4 py-3">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    <span class="text-sm font-medium text-gray-700">Execution Log</span>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">{{ $events->count() }} events</span>
                </div>
            </div>

            <div class="divide-y divide-gray-100">
                @foreach($events as $event)
                    @if($event['type'] === 'approval')
                        {{-- Inline approval card — rendered directly, no toggle button --}}
                        <div class="px-4 py-3 {{ $event['status'] === 'pending' ? 'bg-amber-50' : 'bg-white' }}">
                            <div class="flex gap-3">
                                <div class="mt-0.5 flex-shrink-0">
                                    @if($event['status'] === 'pending')
                                        <svg class="h-4 w-4 text-amber-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                        </svg>
                                    @elseif($event['status'] === 'approved')
                                        <svg class="h-4 w-4 text-green-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                        </svg>
                                    @else
                                        <svg class="h-4 w-4 text-red-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                        </svg>
                                    @endif
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">APPROVAL</span>
                                        <span class="text-[10px] text-gray-400">{{ $event['time']->format('H:i:s') }}</span>
                                    </div>

                                    <p class="mt-1 text-xs font-medium {{ $event['status'] === 'pending' ? 'text-amber-800' : 'text-gray-700' }}">
                                        {{ $event['summary'] }}
                                    </p>

                                    @if($event['status'] === 'pending')
                                        <div class="mt-2 flex gap-2">
                                            <button
                                                wire:click="approveInline('{{ $event['approval_id'] }}')"
                                                wire:loading.attr="disabled"
                                                class="inline-flex items-center rounded-md bg-green-600 px-3 py-1 text-xs font-medium text-white transition-colors hover:bg-green-700 disabled:opacity-50">
                                                <svg class="mr-1 h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                                                </svg>
                                                Approve
                                            </button>
                                            <button
                                                wire:click="openRejectModal('{{ $event['approval_id'] }}')"
                                                class="inline-flex items-center rounded-md border border-red-300 bg-white px-3 py-1 text-xs font-medium text-red-600 transition-colors hover:bg-red-50">
                                                Reject
                                            </button>
                                        </div>
                                    @else
                                        <p class="mt-1 text-xs text-gray-400">
                                            {{ ucfirst($event['status']) }} by {{ $event['reviewer'] ?? 'system' }}
                                            @if($event['reviewed_at'])
                                                &middot; {{ \Carbon\Carbon::parse($event['reviewed_at'])->diffForHumans() }}
                                            @endif
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @else
                        <div>
                            <button wire:click="toggleEvent('{{ $event['id'] }}')"
                                class="flex w-full items-center justify-between px-4 py-2 text-left transition hover:bg-gray-50">
                                <div class="flex items-center gap-3">
                                    {{-- Color dot --}}
                                    @php
                                        $dotColor = match($event['color']) {
                                            'green' => 'bg-green-500',
                                            'red' => 'bg-red-500',
                                            'blue' => 'bg-blue-500',
                                            default => 'bg-gray-400',
                                        };
                                        $typeLabel = match($event['type']) {
                                            'transition' => 'STATE',
                                            'stage' => 'STAGE',
                                            'step' => 'STEP',
                                            'llm_call' => 'LLM',
                                            default => '?',
                                        };
                                        $typeBg = match($event['type']) {
                                            'transition' => 'bg-purple-100 text-purple-700',
                                            'stage' => 'bg-sky-100 text-sky-700',
                                            'step' => 'bg-amber-100 text-amber-700',
                                            'llm_call' => 'bg-emerald-100 text-emerald-700',
                                            default => 'bg-gray-100 text-gray-700',
                                        };
                                    @endphp
                                    <span class="h-2 w-2 rounded-full {{ $dotColor }}"></span>
                                    <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold {{ $typeBg }}">{{ $typeLabel }}</span>
                                    <span class="text-xs text-gray-900">{{ $event['summary'] }}</span>
                                </div>

                                <div class="flex items-center gap-3">
                                    @if($event['detail'])
                                        <span class="text-xs text-gray-500">{{ \Illuminate\Support\Str::limit($event['detail'], 60) }}</span>
                                    @endif
                                    <span class="text-[10px] text-gray-400">{{ $event['time']->format('H:i:s') }}</span>
                                    <svg class="h-3.5 w-3.5 text-gray-400 transition {{ $expandedEventId === $event['id'] ? 'rotate-180' : '' }}"
                                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </div>
                            </button>

                            @if($expandedEventId === $event['id'])
                                <div class="border-t border-gray-100 bg-gray-50 px-4 py-3">
                                    @if($event['detail'])
                                        <p class="text-xs text-gray-600">{{ $event['detail'] }}</p>
                                    @endif

                                    @if($event['metadata'])
                                        <div class="mt-2">
                                            <pre class="max-h-48 overflow-auto rounded bg-gray-900 p-2 text-xs text-green-400">{{ json_encode($event['metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                        </div>
                                    @endif

                                    @if($event['color'] === 'red' && $event['type'] === 'step')
                                        <div class="mt-2 rounded bg-red-50 p-2">
                                            <p class="text-xs font-medium text-red-700">This step failed. Check the error above for details.</p>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
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
