<div class="space-y-6">

    {{-- Summary Cards --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Total Cost</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ $this->formatCredits($totalCost) }}</p>
            <p class="mt-1 text-xs text-gray-400">credits</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Input Tokens</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ $this->formatTokens($totalTokensIn) }}</p>
            <p class="mt-1 text-xs text-gray-400">prompt tokens</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Output Tokens</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ $this->formatTokens($totalTokensOut) }}</p>
            <p class="mt-1 text-xs text-gray-400">completion tokens</p>
        </div>
        <div class="rounded-lg border {{ $cachedCount > 0 ? 'border-green-200 bg-green-50' : 'border-gray-200 bg-white' }} p-4">
            <p class="text-xs font-medium uppercase tracking-wider {{ $cachedCount > 0 ? 'text-green-600' : 'text-gray-500' }}">Cache Hits</p>
            <p class="mt-1 text-2xl font-bold {{ $cachedCount > 0 ? 'text-green-700' : 'text-gray-900' }}">{{ $cachedCount }}</p>
            @if($cachedCount > 0 && $estimatedSavings > 0)
                <p class="mt-1 text-xs text-green-600">~{{ $this->formatCredits($estimatedSavings) }} credits saved</p>
            @else
                <p class="mt-1 text-xs text-gray-400">no savings yet</p>
            @endif
        </div>
    </div>

    {{-- Cost by Stage --}}
    @if($byStage->isNotEmpty())
        <div class="rounded-lg border border-gray-200 bg-white p-5">
            <h3 class="mb-4 text-sm font-semibold text-gray-900">Cost by Pipeline Stage</h3>
            @php $maxStageCost = $byStage->max('cost') ?: 1; @endphp
            <div class="space-y-3">
                @foreach($byStage as $stageType => $data)
                    @php
                        $pct = min(($data['cost'] / $maxStageCost) * 100, 100);
                        $color = match(true) {
                            str_contains($stageType, 'fail') => 'bg-red-400',
                            in_array($stageType, ['building', 'executing']) => 'bg-primary-500',
                            in_array($stageType, ['scoring', 'evaluating']) => 'bg-amber-400',
                            in_array($stageType, ['planning', 'iterating']) => 'bg-blue-400',
                            default => 'bg-gray-400',
                        };
                    @endphp
                    <div class="flex items-center gap-3">
                        <span class="w-24 flex-shrink-0 text-right text-xs text-gray-600 capitalize">{{ str_replace('_', ' ', $stageType) }}</span>
                        <div class="flex-1">
                            <div class="h-5 w-full overflow-hidden rounded-full bg-gray-100">
                                <div class="{{ $color }} h-5 rounded-full transition-all duration-500"
                                     style="width: {{ max($pct, 2) }}%"></div>
                            </div>
                        </div>
                        <span class="w-20 flex-shrink-0 text-right text-xs font-medium text-gray-700">
                            {{ $this->formatCredits($data['cost']) }} cr
                        </span>
                        <span class="w-12 flex-shrink-0 text-right text-xs text-gray-400">
                            {{ $data['runs'] }} run{{ $data['runs'] !== 1 ? 's' : '' }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Cost by Model --}}
    @if($byModel->isNotEmpty())
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-600">Cost by Model</h3>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Provider / Model</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Runs</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium uppercase tracking-wider text-gray-500">In Tokens</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Out Tokens</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Avg Latency</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Total Cost</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($byModel as $providerModel => $data)
                        <tr>
                            <td class="px-4 py-2.5">
                                @php [$prov, $mdl] = explode('/', $providerModel, 2); @endphp
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex rounded px-1.5 py-0.5 text-xs font-medium bg-gray-100 text-gray-600 capitalize">{{ $prov }}</span>
                                    <span class="text-xs text-gray-700 font-mono">{{ $mdl }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-2.5 text-right text-sm text-gray-700">{{ $data['runs'] }}</td>
                            <td class="px-4 py-2.5 text-right text-sm text-gray-700">{{ $this->formatTokens($data['tokens_in']) }}</td>
                            <td class="px-4 py-2.5 text-right text-sm text-gray-700">{{ $this->formatTokens($data['tokens_out']) }}</td>
                            <td class="px-4 py-2.5 text-right text-xs text-gray-500">{{ number_format($data['avg_latency_ms']) }}ms</td>
                            <td class="px-4 py-2.5 text-right text-sm font-semibold text-gray-900">{{ $this->formatCredits($data['cost']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Individual Runs --}}
    @if($runs->isNotEmpty())
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-600">
                    All LLM Calls
                    <span class="ml-1 font-normal text-gray-400">({{ $runs->count() }} total, {{ $cachedCount }} cached)</span>
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Purpose</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Stage</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Model</th>
                            <th class="px-4 py-2.5 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Tokens</th>
                            <th class="px-4 py-2.5 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Latency</th>
                            <th class="px-4 py-2.5 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Cost</th>
                            <th class="px-4 py-2.5 text-right text-xs font-medium uppercase tracking-wider text-gray-500">When</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($runs->sortByDesc('created_at')->take(50) as $run)
                            @php $isCached = $run->cost_credits === 0 && $run->status === 'completed'; @endphp
                            <tr class="{{ $isCached ? 'bg-green-50' : 'hover:bg-gray-50' }}">
                                <td class="px-4 py-2 text-xs text-gray-700">
                                    {{ $run->purpose ?? '-' }}
                                    @if($isCached)
                                        <span class="ml-1 inline-flex rounded px-1 py-0.5 text-xs bg-green-100 text-green-700">cached</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-xs text-gray-500 capitalize">
                                    {{ str_replace('_', ' ', $run->experimentStage?->stage_type ?? '-') }}
                                </td>
                                <td class="px-4 py-2 text-xs font-mono text-gray-600">
                                    {{ $run->provider }}/{{ $run->model }}
                                </td>
                                <td class="px-4 py-2 text-right text-xs text-gray-600">
                                    {{ $this->formatTokens($run->input_tokens) }}+{{ $this->formatTokens($run->output_tokens) }}
                                </td>
                                <td class="px-4 py-2 text-right text-xs text-gray-500">
                                    {{ $run->latency_ms ? number_format($run->latency_ms).'ms' : '-' }}
                                </td>
                                <td class="px-4 py-2 text-right text-xs font-semibold {{ $isCached ? 'text-green-600' : 'text-gray-900' }}">
                                    {{ $isCached ? '0 ✓' : $this->formatCredits($run->cost_credits) }}
                                </td>
                                <td class="px-4 py-2 text-right text-xs text-gray-400">
                                    {{ $run->created_at->diffForHumans() }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($runs->count() > 50)
                <div class="border-t border-gray-200 bg-gray-50 px-4 py-2 text-center text-xs text-gray-400">
                    Showing 50 most recent of {{ $runs->count() }} total calls
                </div>
            @endif
        </div>
    @else
        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center">
            <svg class="mx-auto h-10 w-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p class="mt-3 text-sm text-gray-400">No LLM calls recorded yet.</p>
            <p class="text-xs text-gray-400">Cost breakdown will appear once the experiment starts making AI calls.</p>
        </div>
    @endif

</div>
