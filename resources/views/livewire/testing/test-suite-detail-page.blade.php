<div>
    <div class="mb-6">
        <a href="{{ route('testing.index') }}" class="text-sm text-primary-600 hover:text-primary-800">&larr; Back to test suites</a>
    </div>

    {{-- Suite summary --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-6">
        <div class="flex items-start justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">{{ $suite->name }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ $suite->project?->name ?? 'No project' }}</p>
            </div>
            @if($suite->is_active)
                <span class="inline-flex rounded-full bg-green-100 px-2.5 py-1 text-xs font-medium text-green-700">Active</span>
            @else
                <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-500">Inactive</span>
            @endif
        </div>

        <dl class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-400">Strategy</dt>
                <dd class="mt-1 text-sm font-medium text-gray-700">{{ $suite->test_strategy->label() }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-400">Pass Rate</dt>
                <dd class="mt-1 text-sm font-medium text-gray-700">{{ $suite->pass_rate !== null ? round($suite->pass_rate * 100).'%' : '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-400">Quality Threshold</dt>
                <dd class="mt-1 text-sm font-medium text-gray-700">{{ $suite->quality_threshold !== null ? round($suite->quality_threshold * 100).'%' : '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-400">Test Agents</dt>
                <dd class="mt-1 text-sm font-medium text-gray-700">{{ $suite->test_agent_count }}</dd>
            </div>
        </dl>
    </div>

    {{-- Runs --}}
    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">Test Runs</h3>

    @if($runs->isEmpty())
        <div class="rounded-xl border border-gray-200 bg-white px-6 py-12 text-center">
            <p class="text-sm text-gray-400">No runs recorded for this suite yet.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Score</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Experiment</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Duration</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Started</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($runs as $run)
                        @php
                            $statusColor = match($run->status->value) {
                                'passed' => 'bg-green-100 text-green-700',
                                'failed' => 'bg-red-100 text-red-700',
                                'running' => 'bg-blue-100 text-blue-700',
                                'skipped' => 'bg-gray-100 text-gray-500',
                                default => 'bg-amber-100 text-amber-700',
                            };
                        @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium {{ $statusColor }}">{{ $run->status->label() }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $run->score !== null ? round($run->score * 100).'%' : '—' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ $run->experiment?->title ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $run->duration_ms !== null ? round($run->duration_ms / 1000, 1).'s' : '—' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-400" title="{{ $run->started_at?->toDateTimeString() }}">{{ $run->started_at?->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="mt-4">
        {{ $runs->links() }}
    </div>
</div>
