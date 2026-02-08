@props(['label', 'value', 'change' => null, 'changeType' => 'neutral', 'icon' => null])

<div class="rounded-xl border border-gray-200 bg-white p-6">
    <div class="flex items-center justify-between">
        <p class="text-sm font-medium text-gray-500">{{ $label }}</p>
        @if($icon)
            <span class="text-gray-400">{!! $icon !!}</span>
        @endif
    </div>
    <p class="mt-2 text-3xl font-bold text-gray-900">{{ $value }}</p>
    @if($change !== null)
        <p class="mt-1 text-sm {{ $changeType === 'positive' ? 'text-green-600' : ($changeType === 'negative' ? 'text-red-600' : 'text-gray-500') }}">
            {{ $change }}
        </p>
    @endif
</div>
