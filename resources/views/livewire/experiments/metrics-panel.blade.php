<div class="space-y-6">

    {{-- Section 1: Pipeline Performance --}}
    @if($pipelineTimings->isNotEmpty())
        <div class="rounded-lg border border-gray-200 bg-white p-5">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900">Pipeline Performance</h3>
                <span class="text-xs text-gray-500">Total: {{ $this->formatDuration($totalPipelineSeconds) }}</span>
            </div>

            <div class="space-y-2.5">
                @foreach($pipelineTimings as $stage => $seconds)
                    @php
                        $pct = $maxStageSeconds > 0 ? min(($seconds / $maxStageSeconds) * 100, 100) : 0;
                        $color = match(true) {
                            str_contains($stage, 'fail') => 'bg-red-400',
                            in_array($stage, ['building', 'executing']) => 'bg-primary-500',
                            in_array($stage, ['scoring', 'evaluating']) => 'bg-amber-400',
                            in_array($stage, ['planning', 'iterating']) => 'bg-blue-400',
                            $stage === 'awaiting_approval' => 'bg-purple-400',
                            default => 'bg-gray-400',
                        };
                    @endphp
                    <div class="flex items-center gap-3">
                        <span class="w-36 flex-shrink-0 text-right text-xs text-gray-600">{{ str_replace('_', ' ', $stage) }}</span>
                        <div class="flex-1">
                            <div class="h-5 w-full overflow-hidden rounded-full bg-gray-100">
                                <div class="{{ $color }} h-5 rounded-full transition-all duration-500"
                                     style="width: {{ max($pct, 2) }}%"></div>
                            </div>
                        </div>
                        <span class="w-16 flex-shrink-0 text-right text-xs font-medium text-gray-700">{{ $this->formatDuration($seconds) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Section 2: Outbound & Engagement --}}
    @if($hasOutboundMetrics)
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            {{-- Delivery Rate --}}
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Delivered</p>
                <p class="mt-1 text-2xl font-bold text-gray-900">{{ $deliveryRate }}%</p>
                <p class="mt-1 text-xs text-gray-500">{{ $delivered }}/{{ $totalOutbound }} sent</p>
            </div>

            {{-- Engagement --}}
            @if($avgEngagement !== null)
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Engagement</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($avgEngagement * 100, 0) }}%</p>
                    <p class="mt-1 text-xs text-gray-500">avg score</p>
                </div>
            @endif

            {{-- Opens --}}
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Opens</p>
                <p class="mt-1 text-2xl font-bold text-gray-900">{{ $opens }}</p>
                <p class="mt-1 text-xs text-gray-500">tracked</p>
            </div>

            {{-- Clicks --}}
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Clicks</p>
                <p class="mt-1 text-2xl font-bold text-gray-900">{{ $clicks }}</p>
                <p class="mt-1 text-xs text-gray-500">tracked</p>
            </div>

            {{-- Revenue --}}
            @if($paymentCount > 0)
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Revenue</p>
                    <p class="mt-1 text-2xl font-bold text-green-600">&euro;{{ number_format($totalRevenue, 2) }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ $paymentCount }} payment(s)</p>
                </div>
            @endif
        </div>
    @endif

    {{-- Section 3: Recent Activity --}}
    @if($recentActivity->isNotEmpty())
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-600">Recent Activity</h3>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Value</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Channel</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Source</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($recentActivity as $metric)
                        @php
                            $badgeColor = match($metric->type) {
                                'delivery' => 'bg-green-100 text-green-700',
                                'engagement' => 'bg-blue-100 text-blue-700',
                                'click' => 'bg-amber-100 text-amber-700',
                                'open' => 'bg-purple-100 text-purple-700',
                                'payment' => 'bg-emerald-100 text-emerald-700',
                                default => 'bg-gray-100 text-gray-700',
                            };
                            $displayValue = match($metric->type) {
                                'delivery' => $metric->value >= 1 ? 'Delivered' : 'Failed',
                                'engagement' => number_format($metric->value * 100, 0) . '%',
                                'payment' => '€' . number_format($metric->value / 100, 2),
                                'click', 'open' => $metric->value >= 1 ? 'Yes' : 'No',
                                default => number_format($metric->value, 2),
                            };
                            $channel = $metric->metadata['channel'] ?? ($metric->metadata['simulated'] ?? false ? 'simulated' : '-');
                        @endphp
                        <tr>
                            <td class="px-4 py-2.5">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $badgeColor }}">
                                    {{ $metric->type }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-sm font-medium text-gray-900">{{ $displayValue }}</td>
                            <td class="px-4 py-2.5 text-xs text-gray-500">{{ $channel }}</td>
                            <td class="px-4 py-2.5 text-xs text-gray-500">{{ $metric->source }}</td>
                            <td class="px-4 py-2.5 text-xs text-gray-400">{{ $metric->occurred_at?->diffForHumans() ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Empty State --}}
    @if($pipelineTimings->isEmpty() && !$hasOutboundMetrics && $recentActivity->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-white p-8 text-center">
            <svg class="mx-auto h-10 w-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
            </svg>
            <p class="mt-3 text-sm text-gray-400">No metrics collected yet.</p>
            <p class="text-xs text-gray-400">Metrics will appear once the experiment starts processing.</p>
        </div>
    @endif

</div>
