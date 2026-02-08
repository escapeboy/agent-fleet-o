@props([
    'label' => null,
    'error' => null,
    'compact' => false,
])

<div>
    @if($label)
        <label @if($attributes->get('id')) for="{{ $attributes->get('id') }}" @endif
            class="mb-1 block text-sm font-medium text-gray-700">{{ $label }}</label>
    @endif

    <select {{ $attributes->merge([
        'class' => $compact
            ? 'w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500 focus:outline-none disabled:bg-gray-100 disabled:cursor-not-allowed'
            : 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500 focus:outline-none disabled:bg-gray-100 disabled:cursor-not-allowed',
    ]) }}>
        {{ $slot }}
    </select>

    @if($error)
        <p class="mt-1 text-xs text-red-600">{{ $error }}</p>
    @endif
</div>
