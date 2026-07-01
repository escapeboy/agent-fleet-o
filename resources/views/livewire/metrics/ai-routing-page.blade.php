<div>
    {{-- Time Window Filter --}}
    <div class="mb-6 flex items-center justify-between">
        <div class="flex gap-2">
            @foreach(['24h' => 'Last 24h', '7d' => 'Last 7 days', '30d' => 'Last 30 days'] as $value => $label)
                <button wire:click="$set('timeWindow', '{{ $value }}')"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition
                        {{ $timeWindow === $value ? 'bg-primary-100 text-primary-700' : 'text-gray-500 hover:text-gray-700' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
        <p class="text-xs text-gray-400">AI routing intelligence metrics</p>
    </div>

    {{-- KPI Cards --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Total AI Requests</p>
            <p class="mt-1 text-3xl font-bold text-gray-900">{{ number_format($totalRequests) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Credits Saved</p>
            <p class="mt-1 text-3xl font-bold {{ $costSavings->saved_credits > 0 ? 'text-green-600' : 'text-gray-900' }}">
                ≈ {{ number_format($costSavings->saved_credits) }}
            </p>
            <p class="mt-0.5 text-xs text-gray-400">{{ $costSavings->savings_pct }}% vs always {{ $costSavings->baseline_model ?: 'flagship' }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Escalation Rate</p>
            <p class="mt-1 text-3xl font-bold {{ $escalation->rate > 20 ? 'text-yellow-600' : 'text-gray-900' }}">
                {{ $escalation->rate }}%
            </p>
            <p class="mt-0.5 text-xs text-gray-400">{{ number_format($escalation->escalated) }} of {{ number_format($escalation->total) }} requests</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Verification Catch Rate</p>
            <p class="mt-1 text-3xl font-bold {{ $verification->catch_rate > 10 ? 'text-red-600' : 'text-gray-900' }}">
                {{ $verification->catch_rate }}%
            </p>
            <p class="mt-0.5 text-xs text-gray-400">{{ number_format($verification->failed) }} failed of {{ number_format($verification->total) }} verified</p>
        </div>
    </div>

    {{-- Tier Distribution --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-5 py-3">
            <h3 class="text-sm font-semibold text-gray-900">Tier Distribution</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Tier</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Count</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">%</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Avg Cost</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($tierDistribution as $tier)
                        <tr class="hover:bg-gray-50">
                            <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-gray-900 capitalize">{{ $tier->tier }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700">{{ number_format($tier->count) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700">{{ $tier->percentage }}%</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700">{{ number_format($tier->avg_cost, 1) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-400">No data for the selected time window.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Budget Pressure Distribution --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-5 py-3">
            <h3 class="text-sm font-semibold text-gray-900">Budget Pressure Distribution</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Pressure Level</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Count</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">%</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Avg Cost</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($budgetPressure as $pressure)
                        <tr class="hover:bg-gray-50">
                            <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-gray-900 capitalize">{{ $pressure->level }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700">{{ number_format($pressure->count) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700">{{ $pressure->percentage }}%</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700">{{ number_format($pressure->avg_cost, 1) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-400">No data for the selected time window.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Top Models --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-5 py-3">
            <h3 class="text-sm font-semibold text-gray-900">Top Models</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Model</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Requests</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Avg Latency</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Avg Cost</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Total Cost</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($topModels as $model)
                        <tr class="hover:bg-gray-50">
                            <td class="whitespace-nowrap px-4 py-3">
                                <span class="text-sm font-medium text-gray-900">{{ $model->model_key }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700">{{ number_format($model->requests) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700">{{ number_format($model->avg_latency) }}ms</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700">{{ number_format($model->avg_cost, 1) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium text-gray-900">{{ number_format($model->total_cost) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400">No execution data for the selected time window.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Eval-Grounded Routing (Shadow) --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white">
        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3">
            <h3 class="text-sm font-semibold text-gray-900">Eval-Grounded Routing (Shadow)</h3>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $evalShadow->enabled ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-500' }}">
                {{ $evalShadow->enabled ? 'Observing' : 'Off' }}
            </span>
        </div>
        <div class="grid grid-cols-1 gap-4 p-5 sm:grid-cols-3">
            <div>
                <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Calls Analyzed</p>
                <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($evalShadow->total) }}</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Would Downgrade</p>
                <p class="mt-1 text-2xl font-bold text-gray-900">{{ $evalShadow->downgrade_pct }}%</p>
                <p class="mt-0.5 text-xs text-gray-400">{{ number_format($evalShadow->would_downgrade) }} calls a cheaper model passed the bar</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Est. Extra Savings</p>
                <p class="mt-1 text-2xl font-bold {{ $evalShadow->est_savings_credits > 0 ? 'text-green-600' : 'text-gray-900' }}">≈ {{ number_format($evalShadow->est_savings_credits) }}</p>
                <p class="mt-0.5 text-xs text-gray-400">credits, if recommendations were applied</p>
            </div>
        </div>
    </div>

    {{-- Legend --}}
    <div class="rounded-lg bg-gray-50 p-3">
        <p class="text-xs text-gray-500">
            <strong>Tier</strong> — Complexity classification (light/standard/heavy) used for model routing.
            <strong>Budget Pressure</strong> — Spending pressure level at time of request.
            <strong>Credits Saved</strong> — Credits saved vs re-pricing the same token volume at the flagship baseline model.
            <strong>Eval-Grounded Routing (Shadow)</strong> — Advisory only: how often a cheaper model historically cleared the quality bar for the same task-type. Does not change live routing.
            <strong>Cost</strong> — Credits consumed (1 credit = $0.001).
        </p>
    </div>
</div>
