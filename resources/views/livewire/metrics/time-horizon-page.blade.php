<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Time-Horizon Metrics</h2>
            <p class="text-xs text-gray-500">Aggregated agent_session runs over a rolling window. Inspired by METR task-completion-time research.</p>
        </div>
        <div class="flex gap-2">
            @foreach (['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days', 'all' => 'All time'] as $key => $label)
                <button wire:click="setWindow('{{ $key }}')"
                    class="rounded-lg px-3 py-1.5 text-xs font-medium {{ $window === $key ? 'bg-primary-600 text-white' : 'border border-gray-200 bg-white text-gray-700 hover:bg-gray-50' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    @if ($totals['total'] === 0)
        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">
            No agent sessions in this window. Start a session via an experiment, crew, or external agent runner — metrics will populate as runs complete.
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wider text-gray-500">Total sessions</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format($totals['total']) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wider text-gray-500">Avg duration</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ $totals['completed_durations']['avg'] !== null ? gmdate('H:i:s', (int) $totals['completed_durations']['avg']) : '—' }}</p>
                <p class="text-[11px] text-gray-400">across {{ $totals['completed_durations']['count'] }} completed runs</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wider text-gray-500">P50 / P99</p>
                <p class="mt-1 text-base font-semibold text-gray-900">
                    {{ $totals['completed_durations']['p50'] !== null ? gmdate('H:i:s', (int) $totals['completed_durations']['p50']) : '—' }}
                    /
                    {{ $totals['completed_durations']['p99'] !== null ? gmdate('H:i:s', (int) $totals['completed_durations']['p99']) : '—' }}
                </p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wider text-gray-500">Total LLM cost</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">${{ number_format($eventStats['llm_total_cost_usd'], 4) }}</p>
                <p class="text-[11px] text-gray-400">{{ number_format($eventStats['llm_total_tokens']) }} tokens</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <h3 class="text-sm font-semibold text-gray-700">Sessions by status</h3>
                <ul class="mt-3 space-y-1 text-sm">
                    @foreach ($totals['by_status'] as $status => $count)
                        <li class="flex items-center justify-between">
                            <span class="capitalize text-gray-600">{{ $status }}</span>
                            <span class="font-medium tabular-nums text-gray-900">{{ number_format($count) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <h3 class="text-sm font-semibold text-gray-700">Tools</h3>
                <ul class="mt-3 space-y-1 text-sm">
                    <li class="flex items-center justify-between">
                        <span class="text-gray-600">Calls</span>
                        <span class="font-medium tabular-nums text-gray-900">{{ number_format($eventStats['tool_call_count']) }}</span>
                    </li>
                    <li class="flex items-center justify-between">
                        <span class="text-gray-600">Failures</span>
                        <span class="font-medium tabular-nums {{ $eventStats['tool_failure_count'] > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($eventStats['tool_failure_count']) }}</span>
                    </li>
                </ul>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <h3 class="text-sm font-semibold text-gray-700">Handoffs</h3>
                <p class="mt-3 text-3xl font-semibold text-gray-900">{{ number_format($totals['handoff_count']) }}</p>
                <p class="text-xs text-gray-400">In + Out events combined</p>
            </div>
        </div>

        @if (count($perDay) > 0)
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <h3 class="mb-3 text-sm font-semibold text-gray-700">Sessions per day (last 28d)</h3>
                <div class="grid grid-cols-7 gap-1 sm:grid-cols-14 lg:grid-cols-28">
                    @php
                        $maxCount = max(array_column($perDay, 'count')) ?: 1;
                    @endphp
                    @foreach ($perDay as $row)
                        <div class="relative h-12 rounded bg-gray-100" title="{{ $row['date'] }}: {{ $row['count'] }} sessions">
                            <div class="absolute inset-x-0 bottom-0 rounded bg-primary-500" style="height: {{ max(2, ($row['count'] / $maxCount) * 100) }}%"></div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>
