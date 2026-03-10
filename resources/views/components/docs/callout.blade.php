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
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        @elseif ($type === 'warning')
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
        @elseif ($type === 'tip')
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
            </svg>
        @elseif ($type === 'danger')
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        @endif
    </div>
    <div class="text-sm {{ $s['text'] }}">
        {{ $slot }}
    </div>
</div>
