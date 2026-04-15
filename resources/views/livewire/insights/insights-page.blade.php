<div class="space-y-6">

    {{-- Experiment Funnel (last 30d) --}}
    <div class="rounded-xl border border-gray-200 bg-white">
        <div class="border-b border-gray-100 px-5 py-4">
            <h3 class="text-sm font-semibold text-gray-900">Experiment Pipeline Funnel <span class="text-xs font-normal text-gray-400 ml-1">last 30 days</span></h3>
        </div>
        <div class="flex items-end gap-1 px-5 py-4 overflow-x-auto">
            @php $topCount = max($totalStarted, 1); @endphp
            @foreach($funnel as $step)
                @php $pct = min(100, round(($step['count'] / $topCount) * 100)); @endphp
                <div class="flex flex-col items-center gap-1 min-w-[80px] flex-1">
                    <div class="text-xs font-semibold text-gray-700">{{ number_format($step['count']) }}</div>
                    <div class="w-full rounded-t" style="height: {{ max(8, $pct * 1.2) }}px; background: oklch(from var(--color-primary-500) l c h / {{ 0.3 + $pct / 140 }})"></div>
                    <div class="text-[10px] text-gray-500 capitalize text-center">{{ str_replace('_', ' ', $step['state']) }}</div>
                    <div class="text-[10px] text-gray-400">{{ $pct }}%</div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

        {{-- Slowest Pipeline Stages --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h3 class="text-sm font-semibold text-gray-900">Slowest Pipeline Stages <span class="text-xs font-normal text-gray-400 ml-1">P95 · last 7 days</span></h3>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($slowestStages as $stage)
                    <div class="flex items-center justify-between px-5 py-3">
                        <div>
                            <div class="text-sm font-medium text-gray-800 capitalize">{{ str_replace('_', ' ', $stage->stage_type) }}</div>
                            <div class="text-xs text-gray-500">{{ number_format($stage->total_runs) }} runs · avg {{ number_format($stage->avg_ms / 1000, 1) }}s</div>
                        </div>
                        <div @class([
                            'text-sm font-semibold',
                            'text-red-600' => $stage->p95_ms > 30000,
                            'text-amber-600' => $stage->p95_ms > 10000 && $stage->p95_ms <= 30000,
                            'text-gray-700' => $stage->p95_ms <= 10000,
                        ])>{{ number_format($stage->p95_ms / 1000, 1) }}s P95</div>
                    </div>
                @empty
                    <div class="px-5 py-6 text-sm text-gray-400 text-center">No completed stages in last 7 days.</div>
                @endforelse
            </div>
        </div>

        {{-- Agent Failure Rates --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h3 class="text-sm font-semibold text-gray-900">Agent Failure Rates <span class="text-xs font-normal text-gray-400 ml-1">last 7 days</span></h3>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($agentStats as $row)
                    <div class="px-5 py-3">
                        <div class="flex items-center justify-between">
                            <div class="text-sm font-medium text-gray-800 font-mono text-xs">{{ substr($row->agent_id, 0, 8) }}…</div>
                            <span @class([
                                'text-xs font-semibold px-2 py-0.5 rounded-full',
                                'bg-red-100 text-red-700' => $row->failure_rate > 20,
                                'bg-amber-100 text-amber-700' => $row->failure_rate > 5 && $row->failure_rate <= 20,
                                'bg-green-100 text-green-700' => $row->failure_rate <= 5,
                            ])>{{ $row->failure_rate }}% fail</span>
                        </div>
                        <div class="mt-1 text-xs text-gray-500">{{ $row->total }} runs · {{ $row->failed }} failed · avg {{ number_format($row->avg_ms / 1000, 1) }}s · {{ number_format($row->avg_cost, 0) }} cr</div>
                        <div class="mt-1.5 h-1.5 w-full rounded-full bg-gray-100 overflow-hidden">
                            <div class="h-full rounded-full bg-red-400" style="width: {{ min(100, $row->failure_rate) }}%"></div>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-6 text-sm text-gray-400 text-center">No agent executions in last 7 days.</div>
                @endforelse
            </div>
        </div>

    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

        {{-- Budget Burn (7d) --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h3 class="text-sm font-semibold text-gray-900">Budget Burn <span class="text-xs font-normal text-gray-400 ml-1">daily credits · last 7 days</span></h3>
            </div>
            @php $maxBurn = $budgetBurn->max('credits') ?: 1; @endphp
            <div class="flex items-end gap-2 px-5 pb-4 pt-3">
                @forelse($budgetBurn as $day)
                    @php $h = max(8, round(($day->credits / $maxBurn) * 80)); @endphp
                    <div class="flex flex-col items-center gap-1 flex-1">
                        <div class="text-[10px] text-gray-500">{{ number_format($day->credits, 0) }}</div>
                        <div class="w-full rounded-t bg-primary-500" style="height: {{ $h }}px"></div>
                        <div class="text-[10px] text-gray-400">{{ \Carbon\Carbon::parse($day->day)->format('M j') }}</div>
                    </div>
                @empty
                    <div class="w-full py-6 text-sm text-gray-400 text-center">No budget activity in last 7 days.</div>
                @endforelse
            </div>
        </div>

        {{-- Failure Clusters --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h3 class="text-sm font-semibold text-gray-900">Failure Clusters <span class="text-xs font-normal text-gray-400 ml-1">last 7 days</span></h3>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($failureTransitions as $row)
                    <div class="flex items-center justify-between px-5 py-3">
                        <div class="text-sm text-gray-700 capitalize">{{ str_replace('_', ' ', $row->to_state) }}</div>
                        <div class="text-sm font-semibold text-red-600">{{ number_format($row->count) }}</div>
                    </div>
                @empty
                    <div class="px-5 py-6 text-sm text-gray-400 text-center">No failures in last 7 days. 🎉</div>
                @endforelse
            </div>
        </div>

    </div>

</div>
