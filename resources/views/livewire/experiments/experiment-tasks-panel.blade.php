<div wire:poll.{{ ($running ?? 0) > 0 ? '3' : '10' }}s>
    @if($total > 0)
        {{-- Progress Bar --}}
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4">
            <div class="mb-2 flex items-center justify-between">
                <span class="text-sm font-medium text-gray-700">
                    {{ $isWorkflow ? 'Task Progress' : 'Build Progress' }}
                    @if($isWorkflow && $workflowId)
                        <a href="{{ route('workflows.show', $workflowId) }}" class="ml-2 text-xs font-normal text-primary-600 hover:underline">View Workflow</a>
                    @endif
                </span>
                <span class="text-sm text-gray-500">
                    {{ $completed }}/{{ $total }} completed
                    @if($running > 0)
                        <span class="text-blue-600">&middot; {{ $running }} running</span>
                    @endif
                    @if($failed > 0)
                        <span class="text-red-600">&middot; {{ $failed }} failed</span>
                    @endif
                    @if(($skipped ?? 0) > 0)
                        <span class="text-gray-400">&middot; {{ $skipped }} skipped</span>
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
                                @case('pending')
                                @case('queued')
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100 text-xs text-gray-400">{{ $task->order + 1 }}</span>
                                    @break
                                @case('running')
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-blue-100">
                                        <i class="fa-solid fa-spinner fa-spin text-base text-blue-600"></i>
                                    </span>
                                    @break
                                @case('completed')
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-green-100">
                                        <i class="fa-solid fa-check text-base text-green-600"></i>
                                    </span>
                                    @break
                                @case('failed')
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-red-100">
                                        <i class="fa-solid fa-xmark text-base text-red-600"></i>
                                    </span>
                                    @break
                                @case('skipped')
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100">
                                        <i class="fa-solid fa-minus text-base text-gray-400"></i>
                                    </span>
                                    @break
                            @endswitch

                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ $task->name }}</p>
                                <div class="flex items-center gap-2">
                                    {{-- Type badge --}}
                                    @php
                                        $typeColors = $isWorkflow
                                            ? [
                                                'agent' => 'bg-sky-100 text-sky-700',
                                                'conditional' => 'bg-amber-100 text-amber-700',
                                                'crew' => 'bg-purple-100 text-purple-700',
                                            ]
                                            : [
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

                                    @if($task->provider)
                                        <span class="text-xs text-gray-400">{{ $task->provider }}{{ $task->model ? '/' . $task->model : '' }}</span>
                                    @endif

                                    @if($task->cost_credits)
                                        <span class="text-xs text-gray-400">{{ $task->cost_credits }} cr</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            {{-- Duration --}}
                            @if($task->duration_ms)
                                <span class="text-xs text-gray-500">{{ round($task->duration_ms / 1000) }}s</span>
                            @elseif($task->status === 'running' && $task->started_at)
                                <span class="text-xs text-blue-500">{{ $task->started_at->diffForHumans(short: true, parts: 1) }}</span>
                            @else
                                <span class="text-xs text-gray-300">&mdash;</span>
                            @endif

                            {{-- Expand chevron --}}
                            <i class="fa-solid fa-chevron-down text-base text-gray-400 transition {{ $expandedTaskId === $task->id ? 'rotate-180' : '' }}"></i>
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

                            @if($isWorkflow && $task->status === 'failed' && $task->is_step)
                                <button wire:click="retryStep('{{ $task->id }}')"
                                    wire:confirm="Retry from this step? This will reset this step and all subsequent steps."
                                    class="mb-2 inline-flex items-center gap-1 rounded bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100 transition">
                                    <i class="fa-solid fa-rotate text-sm"></i>
                                    Retry from this step
                                </button>
                            @endif

                            @if($task->output)
                                <div x-data="{ showRaw: false }" class="rounded bg-gray-50 p-2">
                                    <div class="flex items-center justify-between">
                                        <p class="text-xs font-medium text-gray-600">Output</p>
                                        <button @click="showRaw = !showRaw" class="text-xs text-primary-600 hover:underline">
                                            <span x-text="showRaw ? 'Formatted' : 'Raw JSON'"></span>
                                        </button>
                                    </div>

                                    <div x-show="!showRaw" class="prose-output mt-1 max-h-60 overflow-auto max-w-none">
                                        {!! \App\Domain\Experiment\Services\ArtifactContentResolver::renderAsHtml($task->output) !!}
                                    </div>

                                    <pre x-show="showRaw" x-cloak class="mt-1 max-h-48 overflow-auto text-xs text-gray-700">{{ is_array($task->output) ? json_encode($task->output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $task->output }}</pre>
                                </div>
                            @endif

                            @if($task->status === 'running' && $task->is_step)
                                <div x-data="{ showTerminal: false }" class="mb-2">
                                    <button @click="showTerminal = !showTerminal"
                                        class="inline-flex items-center gap-1 rounded bg-gray-800 px-2 py-1 text-xs font-medium text-green-400 hover:bg-gray-700 transition">
                                        <i class="fa-solid fa-terminal text-sm"></i>
                                        <span x-text="showTerminal ? 'Hide Terminal' : 'Show Terminal'"></span>
                                    </button>
                                    <div x-show="showTerminal" x-cloak class="mt-2">
                                        <livewire:experiments.step-terminal-panel :step-id="$task->id" :key="'term-'.$task->id" />
                                    </div>
                                </div>
                            @endif

                            @if(!$task->error && !$task->output && $task->status === 'running')
                                <p class="text-xs text-blue-500">Task is currently running...</p>
                            @endif

                            @if(!$task->error && !$task->output && $task->status === 'pending')
                                <p class="text-xs text-gray-400">Waiting to start...</p>
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
