<div wire:poll.2s>
    @if($total > 0)
        {{-- Progress Bar --}}
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4">
            <div class="mb-2 flex items-center justify-between">
                <span class="text-sm font-medium text-gray-700">Build Progress</span>
                <span class="text-sm text-gray-500">
                    {{ $completed }}/{{ $total }} completed
                    @if($running > 0)
                        <span class="text-blue-600">&middot; {{ $running }} running</span>
                    @endif
                    @if($failed > 0)
                        <span class="text-red-600">&middot; {{ $failed }} failed</span>
                    @endif
                </span>
            </div>
            <div class="h-2.5 w-full rounded-full bg-gray-200">
                @php
                    $completedPct = $total > 0 ? round(($completed / $total) * 100) : 0;
                    $failedPct = $total > 0 ? round(($failed / $total) * 100) : 0;
                    $runningPct = $total > 0 ? round(($running / $total) * 100) : 0;
                @endphp
                <div class="flex h-2.5 overflow-hidden rounded-full">
                    @if($completedPct > 0)
                        <div class="bg-green-500" style="width: {{ $completedPct }}%"></div>
                    @endif
                    @if($runningPct > 0)
                        <div class="animate-pulse bg-blue-500" style="width: {{ $runningPct }}%"></div>
                    @endif
                    @if($failedPct > 0)
                        <div class="bg-red-500" style="width: {{ $failedPct }}%"></div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Task List --}}
        <div class="space-y-2">
            @foreach($tasks as $task)
                <div class="rounded-lg border border-gray-200 bg-white">
                    <button wire:click="toggleTask('{{ $task->id }}')"
                        class="flex w-full items-center justify-between px-4 py-3 text-left transition hover:bg-gray-50">
                        <div class="flex items-center gap-3">
                            {{-- Status Icon --}}
                            @switch($task->status)
                                @case(\App\Domain\Experiment\Enums\ExperimentTaskStatus::Pending)
                                @case(\App\Domain\Experiment\Enums\ExperimentTaskStatus::Queued)
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100 text-xs text-gray-400">{{ $task->sort_order + 1 }}</span>
                                    @break
                                @case(\App\Domain\Experiment\Enums\ExperimentTaskStatus::Running)
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-blue-100">
                                        <svg class="h-4 w-4 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                        </svg>
                                    </span>
                                    @break
                                @case(\App\Domain\Experiment\Enums\ExperimentTaskStatus::Completed)
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-green-100">
                                        <svg class="h-4 w-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                        </svg>
                                    </span>
                                    @break
                                @case(\App\Domain\Experiment\Enums\ExperimentTaskStatus::Failed)
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-red-100">
                                        <svg class="h-4 w-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                    </span>
                                    @break
                                @case(\App\Domain\Experiment\Enums\ExperimentTaskStatus::Skipped)
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100">
                                        <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/>
                                        </svg>
                                    </span>
                                    @break
                            @endswitch

                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ $task->name }}</p>
                                <div class="flex items-center gap-2">
                                    {{-- Type badge --}}
                                    @php
                                        $typeColors = [
                                            'research' => 'bg-purple-100 text-purple-700',
                                            'code' => 'bg-sky-100 text-sky-700',
                                            'design' => 'bg-pink-100 text-pink-700',
                                            'seo' => 'bg-amber-100 text-amber-700',
                                            'strategy' => 'bg-indigo-100 text-indigo-700',
                                            'plan' => 'bg-teal-100 text-teal-700',
                                            'config' => 'bg-slate-100 text-slate-700',
                                            'email_template' => 'bg-orange-100 text-orange-700',
                                        ];
                                        $typeColor = $typeColors[$task->type] ?? 'bg-gray-100 text-gray-700';
                                    @endphp
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $typeColor }}">{{ $task->type }}</span>

                                    @if($task->provider && $task->model)
                                        <span class="text-xs text-gray-400">{{ $task->provider }}/{{ $task->model }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            {{-- Duration --}}
                            @if($task->duration_ms)
                                <span class="text-xs text-gray-500">{{ round($task->duration_ms / 1000) }}s</span>
                            @elseif($task->status === \App\Domain\Experiment\Enums\ExperimentTaskStatus::Running && $task->started_at)
                                <span class="text-xs text-blue-500">{{ $task->started_at->diffForHumans(short: true, parts: 1) }}</span>
                            @else
                                <span class="text-xs text-gray-300">&mdash;</span>
                            @endif

                            {{-- Expand chevron --}}
                            <svg class="h-4 w-4 text-gray-400 transition {{ $expandedTaskId === $task->id ? 'rotate-180' : '' }}"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </div>
                    </button>

                    {{-- Expanded Details --}}
                    @if($expandedTaskId === $task->id)
                        <div class="border-t border-gray-200 px-4 py-3">
                            @if($task->description)
                                <p class="mb-2 text-xs text-gray-500">{{ $task->description }}</p>
                            @endif

                            @if($task->error)
                                <div class="mb-2 rounded bg-red-50 p-2">
                                    <p class="text-xs font-medium text-red-700">Error</p>
                                    <pre class="mt-1 max-h-32 overflow-auto text-xs text-red-600">{{ $task->error }}</pre>
                                </div>
                            @endif

                            @if($task->output_data)
                                <div class="rounded bg-gray-50 p-2">
                                    <p class="text-xs font-medium text-gray-600">Output</p>
                                    <pre class="mt-1 max-h-48 overflow-auto text-xs text-gray-700">{{ json_encode($task->output_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                            @endif

                            @if(!$task->error && !$task->output_data && $task->status === \App\Domain\Experiment\Enums\ExperimentTaskStatus::Running)
                                <p class="text-xs text-blue-500">Task is currently running...</p>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center">
            <p class="text-sm text-gray-400">No tasks created yet.</p>
        </div>
    @endif
</div>
