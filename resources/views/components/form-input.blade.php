@props([
    'label' => null,
    'error' => null,
    'hint' => null,
    'leadingIcon' => false,
    'compact' => false,
])

<div>
    @if($label)
        <label @if($attributes->get('id')) for="{{ $attributes->get('id') }}" @endif
            class="mb-1 block text-sm font-medium text-gray-700">{{ $label }}</label>
    @endif

    @if($leadingIcon)
        <div class="relative">
            {{ $leadingIcon }}
            <input {{ $attributes->merge([
                'type' => 'text',
                'class' => $compact
                    ? 'w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-900 placeholder-gray-400 focus:border-primary-500 focus:ring-primary-500 focus:outline-none disabled:bg-gray-100 disabled:cursor-not-allowed'
                    : 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:border-primary-500 focus:ring-primary-500 focus:outline-none disabled:bg-gray-100 disabled:cursor-not-allowed',
            ]) }} />
        </div>
    @else
        <input {{ $attributes->merge([
            'type' => 'text',
            'class' => $compact
                ? 'w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-900 placeholder-gray-400 focus:border-primary-500 focus:ring-primary-500 focus:outline-none disabled:bg-gray-100 disabled:cursor-not-allowed'
                : 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:border-primary-500 focus:ring-primary-500 focus:outline-none disabled:bg-gray-100 disabled:cursor-not-allowed',
        ]) }} />
    @endif

    @if($hint)
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif

    @if($error)
        <p class="mt-1 text-xs text-red-600">{{ $error }}</p>
    @endif
</div>
