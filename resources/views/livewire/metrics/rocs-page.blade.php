<div>
    {{-- Time Window Filter --}}
    <div class="mb-6 flex items-center justify-between">
        <div class="flex gap-2">
            @foreach(['24h' => 'Last 24h', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days'] as $value => $label)
                <button wire:click="$set('timeWindow', '{{ $value }}')"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition
                        {{ $timeWindow === $value ? 'bg-primary-100 text-primary-700' : 'text-gray-500 hover:text-gray-700' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
        <p class="text-xs text-gray-400">ROI = value delivered ÷ AI spend</p>
    </div>

    {{-- Summary Cards --}}
    @php
        $net = $summary['net_usd'];
        $roi = $summary['roi'];
    @endphp
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">AI Spend</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900">${{ number_format($summary['spend_usd'], 2) }}</p>
            <p class="text-xs text-gray-400">{{ number_format($summary['spend_credits']) }} credits</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Value Delivered</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900">${{ number_format($summary['value_usd'], 2) }}</p>
            <p class="text-xs text-gray-400">{{ $summary['experiment_count'] }} experiments</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Net</p>
            <p class="mt-1 text-2xl font-semibold {{ $net >= 0 ? 'text-green-600' : 'text-red-600' }}">
                {{ $net >= 0 ? '+' : '−' }}${{ number_format(abs($net), 2) }}
            </p>
            <p class="text-xs text-gray-400">value − spend</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Return on Cognitive Spend</p>
            @if($roi !== null)
                <p class="mt-1 text-2xl font-semibold {{ $roi >= 1 ? 'text-green-600' : 'text-red-600' }}">{{ number_format($roi, 2) }}×</p>
            @else
                <p class="mt-1 text-2xl font-semibold text-gray-400">—</p>
            @endif
            <p class="text-xs text-gray-400">value per $1 spent</p>
        </div>
    </div>

    {{-- By Experiment --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white">
        <div class="border-b border-gray-100 px-4 py-3">
            <h3 class="text-sm font-semibold text-gray-900">By Experiment</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Experiment</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Spend</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Value</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Net</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">ROI</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($byExperiment as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('experiments.show', $row['experiment_id']) }}" class="text-sm font-medium text-primary-600 hover:underline">
                                    {{ $row['title'] ?? Str::limit($row['experiment_id'], 8, '…') }}
                                </a>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700">${{ number_format($row['spend_usd'], 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700">${{ number_format($row['value_usd'], 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium {{ $row['net_usd'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                {{ $row['net_usd'] >= 0 ? '+' : '−' }}${{ number_format(abs($row['net_usd']), 2) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                @if($row['roi'] !== null)
                                    <span class="text-sm font-semibold {{ $row['roi'] >= 1 ? 'text-green-600' : 'text-red-600' }}">{{ number_format($row['roi'], 2) }}×</span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400">No spend or value recorded for the selected window.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- By Agent --}}
    <div class="rounded-xl border border-gray-200 bg-white">
        <div class="border-b border-gray-100 px-4 py-3">
            <h3 class="text-sm font-semibold text-gray-900">By Agent</h3>
            <p class="text-xs text-gray-400">Value attributed by each agent's share of an experiment's spend.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Agent</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Spend</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Attributed Value</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Net</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">ROI</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($byAgent as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('agents.show', $row['agent_id']) }}" class="text-sm font-medium text-primary-600 hover:underline">
                                    {{ $row['name'] ?? Str::limit($row['agent_id'], 8, '…') }}
                                </a>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700">${{ number_format($row['spend_usd'], 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700">${{ number_format($row['attributed_value_usd'], 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium {{ $row['net_usd'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                {{ $row['net_usd'] >= 0 ? '+' : '−' }}${{ number_format(abs($row['net_usd']), 2) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                @if($row['roi'] !== null)
                                    <span class="text-sm font-semibold {{ $row['roi'] >= 1 ? 'text-green-600' : 'text-red-600' }}">{{ number_format($row['roi'], 2) }}×</span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400">No agent spend recorded for the selected window.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Legend --}}
    <div class="mt-4 rounded-lg bg-gray-50 p-3">
        <p class="text-xs text-gray-500">
            <strong>Spend</strong> — AI run cost (1 credit = $0.001).
            <strong>Value</strong> — Stripe payments + business-value tags attributed to experiments.
            <strong>ROI</strong> — value ÷ spend; ≥ 1× means the work paid for itself.
        </p>
    </div>
</div>
