@props([
    'label' => null,
    'error' => null,
    'hint' => null,
    'mono' => false,
])

<div>
    @if($label)
        <label @if($attributes->get('id')) for="{{ $attributes->get('id') }}" @endif
            class="mb-1 block text-sm font-medium text-(--color-on-surface)">{{ $label }}</label>
    @endif

    <textarea {{ $attributes->merge([
        'rows' => '3',
        'class' => 'w-full rounded-lg border border-(--color-input-border) bg-(--color-input-bg) px-3 py-2.5 text-sm text-(--color-on-surface) placeholder-(--color-on-surface-muted) focus:border-primary-500 focus:ring-primary-500 focus:outline-none disabled:bg-(--color-surface-alt) disabled:cursor-not-allowed'
            . ($mono ? ' font-mono' : ''),
    ]) }}>{{ $slot }}</textarea>

    @if($hint)
        <p class="mt-1 text-xs text-(--color-on-surface-muted)">{{ $hint }}</p>
    @endif

    @if($error)
        <p class="mt-1 text-xs text-red-600">{{ $error }}</p>
    @endif
</div>
