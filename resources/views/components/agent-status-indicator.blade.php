@props(['status' => 'idle', 'size' => 'md', 'label' => null])

@php
$sizes = ['sm' => 'h-5 w-5', 'md' => 'h-7 w-7', 'lg' => 'h-9 w-9'];
$dotSizes = ['sm' => 'h-2 w-2', 'md' => 'h-3 w-3', 'lg' => 'h-4 w-4'];
$sizeClass = $sizes[$size] ?? $sizes['md'];
$dotClass = $dotSizes[$size] ?? $dotSizes['md'];

$config = match($status) {
    'idle' => ['color' => 'bg-gray-400', 'animation' => 'animate-agent-pulse', 'icon' => null],
    'thinking', 'planning' => ['color' => 'border-indigo-500', 'animation' => 'animate-agent-spin', 'icon' => 'spinner'],
    'executing', 'running', 'building' => ['color' => 'bg-blue-500', 'animation' => 'animate-agent-ping', 'icon' => 'ping'],
    'testing', 'scoring', 'evaluating' => ['color' => 'bg-amber-500', 'animation' => 'animate-agent-typing', 'icon' => 'bar'],
    'error', 'failed', 'execution_failed', 'planning_failed', 'building_failed', 'scoring_failed' => ['color' => 'bg-red-500', 'animation' => 'animate-agent-shake', 'icon' => null],
    'success', 'completed' => ['color' => 'bg-green-500', 'animation' => 'animate-agent-bounce-check', 'icon' => null],
    'paused', 'awaiting_approval', 'pending_approval' => ['color' => 'bg-yellow-400', 'animation' => '', 'icon' => null],
    'killed' => ['color' => 'bg-gray-600', 'animation' => '', 'icon' => null],
    default => ['color' => 'bg-gray-400', 'animation' => '', 'icon' => null],
};
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}>
    <div class="relative flex items-center justify-center {{ $sizeClass }}">
        @if($config['icon'] === 'spinner')
            {{-- Spinning ring --}}
            <div class="rounded-full border-2 {{ $config['color'] }} border-t-transparent {{ $config['animation'] }} {{ $sizeClass }}"></div>
        @elseif($config['icon'] === 'ping')
            {{-- Pinging dot --}}
            <div class="absolute {{ $config['animation'] }} rounded-full {{ $config['color'] }} opacity-75 {{ $dotClass }}"></div>
            <div class="relative rounded-full {{ $config['color'] }} {{ $dotClass }}"></div>
        @elseif($config['icon'] === 'bar')
            {{-- Progress bar animation --}}
            <div class="h-1 w-full overflow-hidden rounded-full bg-gray-200">
                <div class="{{ $config['animation'] }} h-full rounded-full {{ $config['color'] }}"></div>
            </div>
        @else
            {{-- Simple dot --}}
            <div class="rounded-full {{ $config['color'] }} {{ $config['animation'] }} {{ $dotClass }}"></div>
        @endif
    </div>

    @if($label)
        <span class="text-xs text-(--color-on-surface-muted)">{{ $label }}</span>
    @endif
</div>
