@props(['payload' => []])

@php
    $label = $payload['label'] ?? '';
    $value = $payload['value'] ?? 0;
    $unit = $payload['unit'] ?? null;
    $delta = $payload['delta'] ?? null;
    $trend = $payload['trend'] ?? null;
    $context = $payload['context'] ?? null;

    // Format value with thousand separator if it looks like a round number.
    $formattedValue = is_float($value) && floor($value) != $value
        ? number_format($value, 2)
        : number_format((float) $value);

    $trendColor = match($trend) {
        'up' => 'text-emerald-600',
        'down' => 'text-red-600',
        default => 'text-gray-500',
    };
    $trendIcon = match($trend) {
        'up' => 'M5 10l7-7m0 0l7 7m-7-7v18',
        'down' => 'M19 14l-7 7m0 0l-7-7m7 7V3',
        default => 'M5 12h14',
    };
@endphp

{{-- Inline card, NOT collapsible — a single metric should always be visible. --}}
<div class="mt-3 rounded-xl border border-gray-200 bg-white p-3">
    <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500">{{ $label }}</p>
    <div class="mt-1 flex items-baseline gap-2">
        <span class="text-2xl font-semibold text-gray-900">{{ $formattedValue }}</span>
        @if($unit)
            <span class="text-sm text-gray-500">{{ $unit }}</span>
        @endif
    </div>

    @if($delta !== null || $context)
        <div class="mt-1 flex items-center gap-1 text-[11px] {{ $trendColor }}">
            @if($delta !== null)
                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $trendIcon }}"/>
                </svg>
                <span class="font-medium">{{ $delta > 0 ? '+' : '' }}{{ $delta }}</span>
            @endif
            @if($context)
                <span class="text-gray-500">· {{ $context }}</span>
            @endif
        </div>
    @endif
</div>
