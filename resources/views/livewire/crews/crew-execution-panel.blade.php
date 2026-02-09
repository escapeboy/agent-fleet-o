<div wire:poll.2s>
    @if(!$execution)
        <p class="text-sm text-gray-400">Execution not found.</p>
    @else
        {{-- Progress bar --}}
        <div class="mb-4">
            <div class="flex items-center justify-between text-xs text-gray-500 mb-1">
                <span>{{ $validatedCount }}/{{ $tasks->count() }} completed</span>
                <div class="flex gap-3">
                    @if($runningCount > 0)
                        <span class="text-blue-600">{{ $runningCount }} running</span>
                    @endif
                    @if($failedCount > 0)
                        <span class="text-red-600">{{ $failedCount }} failed</span>
                    @endif
                </div>
            </div>
            <div class="h-2 w-full rounded-full bg-gray-200">
                <div class="h-2 rounded-full bg-green-500 transition-all duration-500" style="width: {{ $progress }}%"></div>
            </div>
        </div>

        {{-- Status & controls --}}
        <div class="mb-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                    {{ match($execution->status->value) {
                        'completed' => 'bg-green-100 text-green-700',
                        'failed', 'terminated' => 'bg-red-100 text-red-700',
                        'executing', 'planning' => 'bg-blue-100 text-blue-700',
                        'paused' => 'bg-yellow-100 text-yellow-700',
                        default => 'bg-gray-100 text-gray-700',
                    } }}">
                    {{ $execution->status->label() }}
                </span>
                @if($execution->coordinator_iterations > 0)
                    <span class="text-xs text-gray-500">Iteration {{ $execution->coordinator_iterations }}</span>
                @endif
                @if($execution->total_cost_credits > 0)
                    <span class="text-xs text-gray-500">{{ $execution->total_cost_credits }} credits</span>
                @endif
            </div>

            @if($execution->status->isActive())
                <button wire:click="terminateExecution"
                    wire:confirm="Are you sure you want to terminate this execution?"
                    class="rounded bg-red-100 px-3 py-1 text-xs font-medium text-red-700 hover:bg-red-200">
                    Terminate
                </button>
            @endif
        </div>

        {{-- Task list --}}
        <div class="space-y-1">
            @foreach($tasks as $task)
                <div class="flex items-center gap-3 rounded-lg px-3 py-2 transition hover:bg-gray-50"
                    x-data="{ showDetail: false }">
                    {{-- Status icon --}}
                    @switch($task->status->value)
                        @case('validated')
                            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-green-100 text-green-600">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            </span>
                            @break
                        @case('running')
                        @case('assigned')
                            <span class="flex h-5 w-5 items-center justify-center">
                                <svg class="h-4 w-4 animate-spin text-blue-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                            </span>
                            @break
                        @case('failed')
                        @case('qa_failed')
                            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-red-100 text-red-600">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </span>
                            @break
                        @case('needs_revision')
                            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-amber-100 text-amber-600">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            </span>
                            @break
                        @default
                            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                                <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3"/></svg>
                            </span>
                    @endswitch

                    {{-- Task info --}}
                    <button @click="showDetail = !showDetail" class="flex flex-1 items-center gap-2 text-left">
                        <span class="text-sm font-medium text-gray-700">{{ $task->title }}</span>
                        @if($task->attempt_number > 1)
                            <span class="text-xs text-amber-600">(attempt {{ $task->attempt_number }})</span>
                        @endif
                    </button>

                    {{-- Meta --}}
                    <span class="text-xs text-gray-400">{{ $task->agent?->name ?? '—' }}</span>
                    @if($task->duration_ms)
                        <span class="text-xs text-gray-400">{{ number_format($task->duration_ms / 1000, 1) }}s</span>
                    @endif
                    @if($task->qa_score)
                        <span class="text-xs {{ $task->qa_score >= 0.7 ? 'text-green-600' : 'text-amber-600' }}">
                            {{ number_format($task->qa_score * 100) }}%
                        </span>
                    @endif

                    {{-- Expandable detail --}}
                    <div x-show="showDetail" x-cloak class="col-span-full mt-2 ml-8 rounded-lg bg-gray-50 p-3 text-xs">
                        @if($task->description)
                            <p class="text-gray-600 mb-2">{{ $task->description }}</p>
                        @endif
                        @if($task->qa_feedback)
                            <div class="mb-2">
                                <span class="font-medium text-gray-700">QA Feedback:</span>
                                <span class="text-gray-600">{{ $task->qa_feedback['feedback'] ?? 'No feedback' }}</span>
                            </div>
                        @endif
                        @if($task->error_message)
                            <p class="text-red-600">Error: {{ $task->error_message }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
