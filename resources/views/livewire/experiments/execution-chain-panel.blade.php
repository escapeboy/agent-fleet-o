<div class="space-y-6">

    {{-- Stats row --}}
    @if($stats['total'] > 0)
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Total Events</p>
                <p class="mt-1 text-2xl font-bold text-gray-900">{{ $stats['total'] }}</p>
                <p class="mt-1 text-xs text-gray-400">node executions</p>
            </div>
            <div class="rounded-lg border {{ $stats['completed'] > 0 ? 'border-green-200 bg-green-50' : 'border-gray-200 bg-white' }} p-4">
                <p class="text-xs font-medium uppercase tracking-wider {{ $stats['completed'] > 0 ? 'text-green-600' : 'text-gray-500' }}">Completed</p>
                <p class="mt-1 text-2xl font-bold {{ $stats['completed'] > 0 ? 'text-green-700' : 'text-gray-900' }}">{{ $stats['completed'] }}</p>
                <p class="mt-1 text-xs {{ $stats['completed'] > 0 ? 'text-green-500' : 'text-gray-400' }}">steps finished</p>
            </div>
            <div class="rounded-lg border {{ $stats['failed'] > 0 ? 'border-red-200 bg-red-50' : 'border-gray-200 bg-white' }} p-4">
                <p class="text-xs font-medium uppercase tracking-wider {{ $stats['failed'] > 0 ? 'text-red-600' : 'text-gray-500' }}">Failed</p>
                <p class="mt-1 text-2xl font-bold {{ $stats['failed'] > 0 ? 'text-red-700' : 'text-gray-900' }}">{{ $stats['failed'] }}</p>
                <p class="mt-1 text-xs {{ $stats['failed'] > 0 ? 'text-red-400' : 'text-gray-400' }}">steps errored</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Total Duration</p>
                <p class="mt-1 text-2xl font-bold text-gray-900">{{ $this->formatDuration($stats['total_duration_ms']) }}</p>
                <p class="mt-1 text-xs text-gray-400">wall time</p>
            </div>
        </div>
    @endif

    {{-- Event chain timeline --}}
    @forelse($events as $event)
        @php
            $typeColor = match($event->event_type) {
                'completed' => ['dot' => 'bg-green-500', 'badge' => 'bg-green-100 text-green-700', 'border' => 'border-green-200'],
                'failed' => ['dot' => 'bg-red-500', 'badge' => 'bg-red-100 text-red-700', 'border' => 'border-red-200'],
                'started' => ['dot' => 'bg-blue-500', 'badge' => 'bg-blue-100 text-blue-700', 'border' => 'border-blue-200'],
                'waiting_time' => ['dot' => 'bg-yellow-500', 'badge' => 'bg-yellow-100 text-yellow-700', 'border' => 'border-yellow-200'],
                'waiting_human' => ['dot' => 'bg-purple-500', 'badge' => 'bg-purple-100 text-purple-700', 'border' => 'border-purple-200'],
                'skipped' => ['dot' => 'bg-gray-400', 'badge' => 'bg-gray-100 text-gray-600', 'border' => 'border-gray-200'],
                default => ['dot' => 'bg-gray-400', 'badge' => 'bg-gray-100 text-gray-600', 'border' => 'border-gray-200'],
            };

            $nodeIcon = match($event->node_type) {
                'agent' => '🤖',
                'human_task' => '👤',
                'conditional' => '⑂',
                'switch' => '⇌',
                'dynamic_fork' => '⑃',
                'do_while' => '↺',
                'time_gate' => '⏱',
                'merge' => '⇒',
                'sub_workflow' => '⊞',
                'start' => '▶',
                'end' => '⏹',
                'crew' => '👥',
                default => '◆',
            };
        @endphp

        <div class="flex gap-4">
            {{-- Timeline connector --}}
            <div class="flex flex-col items-center">
                <div class="mt-1.5 h-3 w-3 flex-shrink-0 rounded-full {{ $typeColor['dot'] }}"></div>
                @if(!$loop->last)
                    <div class="mt-1 w-0.5 flex-1 bg-gray-200"></div>
                @endif
            </div>

            {{-- Event card --}}
            <div class="mb-4 flex-1 rounded-lg border {{ $typeColor['border'] }} bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="text-lg leading-none">{{ $nodeIcon }}</span>
                        <div>
                            <p class="text-sm font-semibold text-gray-800">{{ $event->node_label ?: $event->node_type }}</p>
                            <p class="text-xs text-gray-400 capitalize">{{ str_replace('_', ' ', $event->node_type) }}</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $typeColor['badge'] }} capitalize">
                            {{ str_replace('_', ' ', $event->event_type) }}
                        </span>
                        @if($event->duration_ms)
                            <span class="text-xs text-gray-400">{{ $this->formatDuration($event->duration_ms) }}</span>
                        @endif
                        <span class="text-xs text-gray-300">{{ $event->created_at->diffForHumans() }}</span>
                    </div>
                </div>

                @if($event->input_summary)
                    <div class="mt-2">
                        <p class="text-xs font-medium text-gray-500">Input</p>
                        <p class="mt-0.5 line-clamp-2 text-xs text-gray-600">{{ $event->input_summary }}</p>
                    </div>
                @endif

                @if($event->output_summary)
                    <div class="mt-2">
                        <p class="text-xs font-medium {{ $event->event_type === 'failed' ? 'text-red-500' : 'text-gray-500' }}">
                            {{ $event->event_type === 'failed' ? 'Error' : 'Output' }}
                        </p>
                        <p class="mt-0.5 line-clamp-2 text-xs {{ $event->event_type === 'failed' ? 'text-red-600' : 'text-gray-600' }}">
                            {{ $event->output_summary }}
                        </p>
                    </div>
                @endif
            </div>
        </div>
    @empty
        <div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 p-10 text-center">
            <i class="fa-regular fa-clipboard mx-auto text-3xl text-gray-300"></i>
            <p class="mt-2 text-sm text-gray-500">No execution events recorded yet.</p>
            <p class="mt-1 text-xs text-gray-400">Events are captured as each workflow node executes.</p>
        </div>
    @endforelse

</div>
