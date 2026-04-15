<div class="space-y-6">

    {{-- Header KPIs --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Requests (24h)</div>
            <div class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($totalRequests) }}</div>
            <div class="mt-1 text-xs text-gray-500">{{ $successRequests }} successful</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Total Cost (24h)</div>
            <div class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($totalCostCredits, 0) }} cr</div>
            <div class="mt-1 text-xs text-gray-500">${{ number_format($totalCostCredits / 1000, 2) }} USD</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Error Rate</div>
            <div class="mt-1 text-2xl font-bold {{ $errorRate > 5 ? 'text-red-600' : 'text-gray-900' }}">{{ $errorRate }}%</div>
            <div class="mt-1 text-xs text-gray-500">of all requests</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Avg Latency</div>
            <div class="mt-1 text-2xl font-bold text-gray-900">{{ $avgLatencyMs ? number_format($avgLatencyMs / 1000, 1) . 's' : '—' }}</div>
            <div class="mt-1 text-xs text-gray-500">successful requests</div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

        {{-- Circuit Breaker Health --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h3 class="text-sm font-semibold text-gray-900">Circuit Breaker Health</h3>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($circuitBreakers as $provider => $cb)
                    <div class="flex items-center justify-between px-5 py-3">
                        <div class="font-medium text-sm text-gray-800">{{ ucfirst($provider) }}</div>
                        <div class="flex items-center gap-3 text-sm">
                            <span class="text-gray-500">{{ $cb->failure_count }} failures</span>
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-green-100 text-green-700' => $cb->state === 'closed',
                                'bg-red-100 text-red-700' => $cb->state === 'open',
                                'bg-yellow-100 text-yellow-700' => $cb->state === 'half_open',
                            ])>{{ ucfirst(str_replace('_', ' ', $cb->state)) }}</span>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-gray-400">No circuit breaker records</div>
                @endforelse
            </div>
        </div>

        {{-- Cost by Provider --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h3 class="text-sm font-semibold text-gray-900">Cost by Provider (24h)</h3>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($costByProvider as $row)
                    <div class="flex items-center justify-between px-5 py-3">
                        <div>
                            <div class="font-medium text-sm text-gray-800">{{ ucfirst($row->provider) }}</div>
                            <div class="text-xs text-gray-500">{{ number_format($row->request_count) }} requests</div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-semibold text-gray-900">{{ number_format($row->total_cost, 0) }} cr</div>
                            <div class="text-xs text-gray-500">${{ number_format($row->total_cost / 1000, 2) }}</div>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-gray-400">No data in last 24h</div>
                @endforelse
            </div>
        </div>

        {{-- Model Usage Breakdown --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h3 class="text-sm font-semibold text-gray-900">Model Usage (24h)</h3>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($usageByModel as $row)
                    <div class="flex items-center justify-between px-5 py-3">
                        <div>
                            <div class="font-medium text-sm text-gray-800">{{ $row->model }}</div>
                            <div class="text-xs text-gray-500">{{ number_format($row->avg_latency / 1000, 1) }}s avg</div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-semibold text-gray-900">{{ number_format($row->request_count) }}</div>
                            <div class="text-xs text-gray-500">{{ number_format($row->total_cost, 0) }} cr</div>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-gray-400">No data in last 24h</div>
                @endforelse
            </div>
        </div>

        {{-- Top Teams by Spend --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h3 class="text-sm font-semibold text-gray-900">Top Teams by Spend (24h)</h3>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($topTeamsBySpend as $i => $row)
                    <div class="flex items-center justify-between px-5 py-3">
                        <div class="flex items-center gap-3">
                            <span class="text-xs font-medium text-gray-400 w-4">{{ $i + 1 }}</span>
                            <div>
                                <div class="font-medium text-sm text-gray-800">{{ $row->team_name }}</div>
                                <div class="text-xs text-gray-500">{{ number_format($row->request_count) }} requests</div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-semibold text-gray-900">{{ number_format($row->total_cost, 0) }} cr</div>
                            <div class="text-xs text-gray-500">${{ number_format($row->total_cost / 1000, 2) }}</div>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-gray-400">No data in last 24h</div>
                @endforelse
            </div>
        </div>

    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

        {{-- Semantic Cache Stats --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="text-sm font-semibold text-gray-900 mb-4">Semantic Cache</h3>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <div class="text-xs text-gray-500">Total Entries</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ number_format($cacheTotal) }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">With Hits</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ number_format($cacheUsed) }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Total Hits</div>
                    <div class="mt-1 text-xl font-bold text-green-600">{{ number_format($totalHits) }}</div>
                </div>
            </div>
            @if($cacheTotal > 0)
                <div class="mt-4">
                    <div class="flex justify-between text-xs text-gray-500 mb-1">
                        <span>Cache utilisation</span>
                        <span>{{ round(($cacheUsed / $cacheTotal) * 100) }}%</span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-1.5">
                        <div class="bg-primary-500 h-1.5 rounded-full" style="width: {{ round(($cacheUsed / $cacheTotal) * 100) }}%"></div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Recent Errors --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h3 class="text-sm font-semibold text-gray-900">Top Errors (24h)</h3>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($errorsByType as $err)
                    <div class="flex items-center justify-between px-5 py-3">
                        <div class="text-sm text-gray-700 truncate max-w-xs">{{ $err->error ?: 'Unknown' }}</div>
                        <span class="ml-3 shrink-0 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-600">{{ $err->count }}</span>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-gray-400">No errors in last 24h</div>
                @endforelse
            </div>
        </div>

    </div>

</div>
