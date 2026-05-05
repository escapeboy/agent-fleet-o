@props(['type' => 'info'])

@php
$styles = [
    'info'    => ['bg' => 'bg-blue-50',   'border' => 'border-blue-200',  'icon_color' => 'text-blue-500',  'text' => 'text-blue-800'],
    'warning' => ['bg' => 'bg-amber-50',  'border' => 'border-amber-200', 'icon_color' => 'text-amber-500', 'text' => 'text-amber-800'],
    'tip'     => ['bg' => 'bg-green-50',  'border' => 'border-green-200', 'icon_color' => 'text-green-500', 'text' => 'text-green-800'],
    'danger'  => ['bg' => 'bg-red-50',    'border' => 'border-red-200',   'icon_color' => 'text-red-500',   'text' => 'text-red-800'],
];
$s = $styles[$type] ?? $styles['info'];
@endphp

<div class="my-6 flex gap-3 rounded-lg border {{ $s['border'] }} {{ $s['bg'] }} p-4">
    <div class="mt-0.5 shrink-0 {{ $s['icon_color'] }}">
        @if ($type === 'info')
            <i class="fa-solid fa-circle-info text-lg" aria-hidden="true"></i>
        @elseif ($type === 'warning')
            <i class="fa-solid fa-triangle-exclamation text-lg" aria-hidden="true"></i>
        @elseif ($type === 'tip')
            <i class="fa-solid fa-lightbulb text-lg" aria-hidden="true"></i>
        @elseif ($type === 'danger')
            <i class="fa-solid fa-circle-exclamation text-lg" aria-hidden="true"></i>
        @endif
    </div>
    <div class="text-sm {{ $s['text'] }}">
        {{ $slot }}
    </div>
</div>
