@props(['payload' => []])

@php
    // NOT collapsible by design — progress must be live-visible.
    $label = $payload['label'] ?? '';
    $progress = (int) ($payload['progress'] ?? 0);
    $state = $payload['state'] ?? 'running';
    $eta = $payload['eta'] ?? null;

    $barColor = match($state) {
        'completed' => 'bg-emerald-500',
        'failed' => 'bg-red-500',
        'paused' => 'bg-gray-400',
        'pending' => 'bg-gray-300',
        default => 'bg-indigo-500',
    };
    $stateColor = match($state) {
        'completed' => 'text-emerald-700',
        'failed' => 'text-red-700',
        'paused' => 'text-gray-600',
        default => 'text-indigo-700',
    };
@endphp

<div class="mt-3 rounded-xl border border-gray-200 bg-white p-3"
    role="progressbar"
    aria-valuemin="0"
    aria-valuemax="100"
    aria-valuenow="{{ $progress }}"
    aria-label="{{ $label }}"
>
    <div class="mb-1.5 flex items-center justify-between text-[11px]">
        <span class="font-medium text-gray-700">{{ $label }}</span>
        <span class="{{ $stateColor }}">{{ $progress }}% · {{ $state }}</span>
    </div>
    <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
        <div class="h-full rounded-full {{ $barColor }} transition-all" style="width: {{ $progress }}%"></div>
    </div>
    @if($eta)
        <p class="mt-1 text-[10px] text-gray-500">ETA: {{ $eta }}</p>
    @endif
</div>
