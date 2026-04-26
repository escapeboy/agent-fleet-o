@php
    $score = $sla['health_score'] ?? null;
    $palette = match (true) {
        $score === null => 'gray',
        $score >= 0.8 => 'emerald',
        $score >= 0.5 => 'amber',
        default => 'rose',
    };
    $colors = [
        'gray'    => ['bg' => 'bg-gray-50', 'border' => 'border-gray-200', 'text' => 'text-gray-700', 'badge' => 'bg-gray-100'],
        'emerald' => ['bg' => 'bg-emerald-50', 'border' => 'border-emerald-200', 'text' => 'text-emerald-900', 'badge' => 'bg-emerald-100'],
        'amber'   => ['bg' => 'bg-amber-50', 'border' => 'border-amber-200', 'text' => 'text-amber-900', 'badge' => 'bg-amber-100'],
        'rose'    => ['bg' => 'bg-rose-50', 'border' => 'border-rose-200', 'text' => 'text-rose-900', 'badge' => 'bg-rose-100'],
    ][$palette];
@endphp

<div>
    <div class="rounded-xl border {{ $colors['border'] }} {{ $colors['bg'] }} p-3">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg {{ $colors['badge'] }} {{ $colors['text'] }}">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <div>
                    <h3 class="text-sm font-semibold {{ $colors['text'] }}">{{ __('Agent SLA') }} <span class="ml-1 text-xs font-normal opacity-75">({{ __('last :n days', ['n' => $sla['period_days']]) }})</span></h3>
                    <p class="text-xs opacity-75 {{ $colors['text'] }}">
                        @if($sla['total_runs'] === 0)
                            {{ __('No runs yet — metrics will appear after the first execution.') }}
                        @elseif($score !== null)
                            {{ __('Health score :score / 1.00', ['score' => number_format($score, 2)]) }}
                        @else
                            {{ __(':n runs recorded', ['n' => $sla['total_runs']]) }}
                        @endif
                    </p>
                </div>
            </div>

            @if($sla['total_runs'] > 0)
                <div class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                    <div class="rounded-lg bg-white px-3 py-1.5 text-right">
                        <div class="opacity-75 {{ $colors['text'] }}">{{ __('Success') }}</div>
                        <div class="font-semibold {{ $colors['text'] }}">
                            {{ $sla['success_rate'] !== null ? number_format($sla['success_rate'], 1).'%' : '—' }}
                        </div>
                    </div>
                    <div class="rounded-lg bg-white px-3 py-1.5 text-right">
                        <div class="opacity-75 {{ $colors['text'] }}">{{ __('Latency p95') }}</div>
                        <div class="font-semibold {{ $colors['text'] }}">
                            @if($sla['latency_p95_ms'] !== null)
                                {{ $sla['latency_p95_ms'] >= 1000
                                    ? number_format($sla['latency_p95_ms'] / 1000, 1).'s'
                                    : $sla['latency_p95_ms'].'ms' }}
                            @else
                                —
                            @endif
                        </div>
                    </div>
                    <div class="rounded-lg bg-white px-3 py-1.5 text-right">
                        <div class="opacity-75 {{ $colors['text'] }}">{{ __('Avg cost') }}</div>
                        <div class="font-semibold {{ $colors['text'] }}">
                            {{ $sla['avg_cost_credits'] !== null ? $sla['avg_cost_credits'].' cr' : '—' }}
                        </div>
                    </div>
                    <div class="rounded-lg bg-white px-3 py-1.5 text-right">
                        <div class="opacity-75 {{ $colors['text'] }}">{{ __('Runs') }}</div>
                        <div class="font-semibold {{ $colors['text'] }}">{{ $sla['total_runs'] }}</div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
