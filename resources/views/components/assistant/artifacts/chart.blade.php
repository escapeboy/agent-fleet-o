@props(['payload' => [], 'open' => false])

@php
    $title = $payload['title'] ?? 'Chart';
    $chartType = $payload['chart_type'] ?? 'bar';
    $points = $payload['data_points'] ?? [];
    $xLabel = $payload['x_axis_label'] ?? '';
    $yLabel = $payload['y_axis_label'] ?? '';
    $maxValue = max(1, ...array_column($points, 'value'));
@endphp

<x-assistant.artifacts.wrapper type="chart" :title="$title" icon="chart" :open="$open">
    <div class="max-h-64 w-full" role="img" aria-label="{{ $chartType }} chart: {{ $title }}">
        {{-- Minimal SVG-free bar renderer. Good enough for the inline panel; --}}
        {{-- callers who need a real chart library open the pop-out modal.     --}}
        @if($chartType === 'bar' || $chartType === 'line' || $chartType === 'area')
            <div class="space-y-1">
                @foreach($points as $pt)
                    @php $pct = max(0, min(100, ($pt['value'] / $maxValue) * 100)); @endphp
                    <div class="flex items-center gap-2 text-[11px]">
                        <span class="w-16 truncate text-gray-500" title="{{ $pt['label'] }}">{{ $pt['label'] }}</span>
                        <div class="relative h-3 flex-1 overflow-hidden rounded bg-gray-100">
                            <div class="absolute inset-y-0 left-0 rounded bg-indigo-500" style="width: {{ $pct }}%"></div>
                        </div>
                        <span class="w-12 text-right font-mono text-gray-700">{{ $pt['value'] }}</span>
                    </div>
                @endforeach
            </div>
        @elseif($chartType === 'pie')
            <ul class="space-y-1">
                @php $total = max(1, array_sum(array_column($points, 'value'))); @endphp
                @foreach($points as $pt)
                    @php $pct = round(($pt['value'] / $total) * 100, 1); @endphp
                    <li class="flex items-center gap-2 text-[11px]">
                        <span class="h-2 w-2 rounded-full bg-indigo-500" style="opacity: {{ 1 - ($loop->index * 0.15) }}"></span>
                        <span class="flex-1 truncate text-gray-700">{{ $pt['label'] }}</span>
                        <span class="font-mono text-gray-500">{{ $pct }}%</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if($xLabel || $yLabel)
        <p class="mt-2 text-[10px] text-gray-400">
            @if($xLabel) X: {{ $xLabel }} @endif
            @if($yLabel) · Y: {{ $yLabel }} @endif
        </p>
    @endif

    <p class="sr-only">
        {{ ucfirst($chartType) }} chart with {{ count($points) }} data points from tool {{ $payload['source_tool'] ?? 'unknown' }}.
        Values:
        @foreach($points as $pt) {{ $pt['label'] }}: {{ $pt['value'] }}@if(! $loop->last),@endif @endforeach
    </p>
</x-assistant.artifacts.wrapper>
