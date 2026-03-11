<div>
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="{{ route('chatbots.show', $chatbot) }}" class="text-sm text-gray-500 hover:text-gray-700">← {{ $chatbot->name }}</a>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs text-gray-500">Period:</span>
            <select wire:model.live="days" class="rounded-md border border-gray-300 py-1 pl-2 pr-6 text-sm focus:border-primary-500 focus:ring-primary-500">
                <option value="7">Last 7 days</option>
                <option value="30">Last 30 days</option>
                <option value="90">Last 90 days</option>
            </select>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Containment Rate</p>
            <p class="mt-2 text-3xl font-semibold {{ $containmentRate >= 80 ? 'text-green-600' : ($containmentRate >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                {{ $containmentRate }}%
            </p>
            <p class="mt-1 text-xs text-gray-400">Resolved without escalation</p>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Avg Confidence</p>
            <p class="mt-2 text-3xl font-semibold text-gray-900">
                {{ $avgConfidence !== null ? $avgConfidence.'%' : '—' }}
            </p>
            <p class="mt-1 text-xs text-gray-400">Across all responses</p>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Avg Response Time</p>
            <p class="mt-2 text-3xl font-semibold text-gray-900">
                {{ $avgLatencyMs !== null ? number_format($avgLatencyMs / 1000, 1).'s' : '—' }}
            </p>
            <p class="mt-1 text-xs text-gray-400">End-to-end latency</p>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Total Sessions</p>
            <p class="mt-2 text-3xl font-semibold text-gray-900">{{ number_format($totalSessions) }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ $totalMessages }} messages sent</p>
        </div>
    </div>

    {{-- Sessions per day chart --}}
    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-6">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-sm font-medium text-gray-500">Sessions per Day</h3>
            <span class="text-xs text-gray-400">Last {{ $days }} days</span>
        </div>

        @php
            $maxCount = max(array_column($dailySeries, 'count')) ?: 1;
        @endphp

        <div class="flex h-24 items-end gap-px">
            @foreach($dailySeries as $day)
                @php
                    $barHeight = $day['count'] > 0 ? max(($day['count'] / $maxCount) * 100, 4) : 0;
                    $isToday = $day['date'] === now()->format('Y-m-d');
                @endphp
                <div class="group relative flex-1" title="{{ $day['date'] }}: {{ $day['count'] }} sessions">
                    <div class="w-full rounded-sm transition-all {{ $isToday ? 'bg-primary-500' : 'bg-primary-200 group-hover:bg-primary-400' }}"
                         style="height: {{ $barHeight }}%"></div>
                </div>
            @endforeach
        </div>

        <div class="mt-1 flex justify-between text-xs text-gray-400">
            <span>{{ $days }} days ago</span>
            <span>Today</span>
        </div>
    </div>

    {{-- Low-confidence / unanswered questions --}}
    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-6">
        <h3 class="mb-4 text-sm font-medium text-gray-500">Unanswered Questions
            <span class="ml-1 text-xs text-gray-400">(low confidence, no KB match)</span>
        </h3>

        @forelse($lowConfidenceMessages as $msg)
            <div class="mb-3 rounded-lg border border-yellow-100 bg-yellow-50 p-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-medium text-gray-500">
                            Session: {{ $msg->session?->channel ?? 'unknown' }}
                            · {{ $msg->created_at->diffForHumans() }}
                        </p>
                        <p class="mt-1 text-sm text-gray-800">{{ Str::limit($msg->draft_content ?? $msg->content ?? '—', 200) }}</p>
                    </div>
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium
                        {{ (float)$msg->confidence < 0.5 ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700' }}">
                        {{ round((float)$msg->confidence * 100) }}%
                    </span>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-400">No low-confidence messages in this period. 🎉</p>
        @endforelse
    </div>
</div>
