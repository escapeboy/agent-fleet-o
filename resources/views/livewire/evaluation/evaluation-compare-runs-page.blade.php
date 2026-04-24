<div class="container mx-auto max-w-7xl space-y-6 px-4 py-6">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900">Compare Evaluation Runs</h1>
        <p class="mt-1 text-sm text-gray-500">
            Pick two runs (A = baseline, B = candidate). The per-case table shows where B regressed — largest score drops first.
        </p>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <label class="block text-xs font-medium text-gray-700">Run A (baseline)</label>
            <select wire:model.live="runA" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                <option value="">—</option>
                @foreach($candidateRuns as $r)
                    <option value="{{ $r->id }}">
                        {{ $r->created_at?->format('Y-m-d H:i') }} · {{ $r->status?->value ?? $r->status }}
                        @if(!empty($r->summary['overall_avg_score'])) · avg {{ number_format($r->summary['overall_avg_score'], 2) }} @endif
                    </option>
                @endforeach
            </select>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <label class="block text-xs font-medium text-gray-700">Run B (candidate)</label>
            <select wire:model.live="runB" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                <option value="">—</option>
                @foreach($candidateRuns as $r)
                    <option value="{{ $r->id }}">
                        {{ $r->created_at?->format('Y-m-d H:i') }} · {{ $r->status?->value ?? $r->status }}
                        @if(!empty($r->summary['overall_avg_score'])) · avg {{ number_format($r->summary['overall_avg_score'], 2) }} @endif
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    @if($runA && $runB)
        {{-- Aggregate comparison --}}
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-sm font-semibold text-gray-900">Aggregate</h2>
            <div class="grid grid-cols-2 gap-6 md:grid-cols-4">
                <div>
                    <div class="text-xs text-gray-500">Overall avg score</div>
                    <div class="mt-1 flex items-baseline gap-2">
                        <span class="text-xl font-mono text-gray-700">{{ number_format($runA->summary['overall_avg_score'] ?? 0, 2) }}</span>
                        <span class="text-gray-400">→</span>
                        <span class="text-xl font-mono text-gray-900">{{ number_format($runB->summary['overall_avg_score'] ?? 0, 2) }}</span>
                        @if($scoreDelta !== null)
                            <span class="ml-2 text-xs font-medium
                                @if($scoreDelta > 0.2) text-emerald-700
                                @elseif($scoreDelta < -0.2) text-red-700
                                @else text-gray-500
                                @endif">
                                {{ $scoreDelta >= 0 ? '+' : '' }}{{ number_format($scoreDelta, 2) }}
                            </span>
                        @endif
                    </div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Pass rate</div>
                    <div class="mt-1 text-lg font-mono text-gray-700">
                        {{ $runA->summary['pass_rate_pct'] ?? 0 }}% → {{ $runB->summary['pass_rate_pct'] ?? 0 }}%
                    </div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Total cases</div>
                    <div class="mt-1 text-lg font-mono text-gray-700">
                        {{ $runA->summary['total_cases'] ?? 0 }} · {{ $runB->summary['total_cases'] ?? 0 }}
                    </div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Target</div>
                    <div class="mt-1 text-[11px] font-mono text-gray-700 leading-tight">
                        <div>A: {{ $runA->summary['target_provider'] ?? '?' }} / {{ $runA->summary['target_model'] ?? '?' }}</div>
                        <div>B: {{ $runB->summary['target_provider'] ?? '?' }} / {{ $runB->summary['target_model'] ?? '?' }}</div>
                    </div>
                </div>
            </div>

            @if(!empty($runA->aggregate_scores) && !empty($runB->aggregate_scores))
                <div class="mt-6">
                    <div class="mb-2 text-xs font-medium text-gray-600">Per-criterion averages</div>
                    <div class="grid grid-cols-1 gap-2 md:grid-cols-4">
                        @foreach(array_keys(array_merge($runA->aggregate_scores, $runB->aggregate_scores)) as $criterion)
                            @php
                                $a = $runA->aggregate_scores[$criterion] ?? null;
                                $b = $runB->aggregate_scores[$criterion] ?? null;
                                $delta = ($a !== null && $b !== null) ? round($b - $a, 2) : null;
                            @endphp
                            <div class="rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-xs">
                                <div class="font-medium text-gray-700">{{ $criterion }}</div>
                                <div class="mt-0.5 font-mono text-gray-600">
                                    {{ $a !== null ? number_format($a, 2) : '—' }} → {{ $b !== null ? number_format($b, 2) : '—' }}
                                    @if($delta !== null)
                                        <span class="ml-1 @if($delta > 0.2) text-emerald-700 @elseif($delta < -0.2) text-red-700 @else text-gray-500 @endif">
                                            ({{ $delta >= 0 ? '+' : '' }}{{ number_format($delta, 2) }})
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Per-case diff table --}}
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-6 py-3">
                <h2 class="text-sm font-semibold text-gray-900">Per-case diff ({{ count($perCaseDiff) }} cases)</h2>
                <p class="mt-0.5 text-xs text-gray-500">Sorted by score delta — biggest regressions first.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-2">Input</th>
                            <th class="px-4 py-2 text-center">A</th>
                            <th class="px-4 py-2 text-center">B</th>
                            <th class="px-4 py-2 text-center">Δ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($perCaseDiff as $row)
                            <tr class="@if($row['delta'] !== null && $row['delta'] < -1) bg-red-50 @elseif($row['delta'] !== null && $row['delta'] > 1) bg-emerald-50 @endif">
                                <td class="max-w-[600px] px-4 py-2 align-top">
                                    <div class="truncate text-xs text-gray-700">{{ \Illuminate\Support\Str::limit($row['input'], 120) }}</div>
                                    @if($row['a_error'] || $row['b_error'])
                                        <div class="mt-1 text-[10px] text-red-600">
                                            @if($row['a_error']) A: {{ \Illuminate\Support\Str::limit($row['a_error'], 60) }} @endif
                                            @if($row['b_error']) · B: {{ \Illuminate\Support\Str::limit($row['b_error'], 60) }} @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-center font-mono text-xs text-gray-600">
                                    {{ $row['a_score'] !== null ? number_format($row['a_score'], 2) : '—' }}
                                </td>
                                <td class="px-4 py-2 text-center font-mono text-xs text-gray-900">
                                    {{ $row['b_score'] !== null ? number_format($row['b_score'], 2) : '—' }}
                                </td>
                                <td class="px-4 py-2 text-center font-mono text-xs
                                    @if($row['delta'] !== null && $row['delta'] < -0.5) text-red-700 font-semibold
                                    @elseif($row['delta'] !== null && $row['delta'] > 0.5) text-emerald-700
                                    @else text-gray-500
                                    @endif">
                                    @if($row['delta'] !== null){{ $row['delta'] >= 0 ? '+' : '' }}{{ number_format($row['delta'], 2) }}@else —@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-8 text-center text-sm text-gray-500">No matching cases between the two runs.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-8 text-center text-sm text-gray-500">
            Select two runs above to compare.
        </div>
    @endif
</div>
