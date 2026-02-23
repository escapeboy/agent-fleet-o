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
        <p class="text-xs text-gray-400">Quality scores from LLM-as-judge evaluation</p>
    </div>

    {{-- Model Comparison Table --}}
    <div class="rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Model</th>
                        @foreach([
                            'total_executions' => 'Executions',
                            'avg_quality' => 'Avg Quality',
                            'avg_duration_ms' => 'Avg Duration',
                            'avg_cost_credits' => 'Avg Cost',
                            'total_cost' => 'Total Cost',
                        ] as $col => $label)
                            <th wire:click="sort('{{ $col }}')"
                                class="cursor-pointer px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                                {{ $label }}
                                @if($sortBy === $col)
                                    <span class="ml-0.5">{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($models as $model)
                        <tr class="hover:bg-gray-50">
                            <td class="whitespace-nowrap px-4 py-3">
                                <span class="text-sm font-medium text-gray-900">{{ $model->model_key }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700">
                                {{ number_format($model->total_executions) }}
                                @if($model->evaluated_count > 0)
                                    <span class="text-xs text-gray-400">({{ $model->evaluated_count }} scored)</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                @if($model->avg_quality !== null)
                                    @php $pct = round($model->avg_quality * 100); @endphp
                                    <span class="text-sm font-semibold {{ $pct >= 80 ? 'text-green-600' : ($pct >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                        {{ $pct }}%
                                    </span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700">
                                {{ number_format($model->avg_duration_ms) }}ms
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-700">
                                {{ number_format($model->avg_cost_credits, 1) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium text-gray-900">
                                {{ number_format($model->total_cost) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-400">
                                No execution data for the selected time window.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Legend --}}
    <div class="mt-4 rounded-lg bg-gray-50 p-3">
        <p class="text-xs text-gray-500">
            <strong>Quality Score</strong> — Average score (0-100%) from LLM-as-judge evaluations.
            Enable evaluation on individual agents/skills to start collecting scores.
            <strong>Cost</strong> — Credits consumed (1 credit = $0.001).
        </p>
    </div>
</div>
