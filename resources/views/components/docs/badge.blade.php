@props(['text' => 'Pro', 'color' => 'purple'])

@php
$colors = [
    'purple' => 'bg-purple-100 text-purple-700 ring-purple-200',
    'blue'   => 'bg-blue-100 text-blue-700 ring-blue-200',
    'green'  => 'bg-green-100 text-green-700 ring-green-200',
    'gray'   => 'bg-gray-100 text-gray-700 ring-gray-200',
];
$cls = $colors[$color] ?? $colors['purple'];
@endphp

<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 {{ $cls }}">
    {{ $text }}
</span>
