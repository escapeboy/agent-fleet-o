@props([
    'label' => null,
])

<label class="inline-flex items-center gap-2">
    <input {{ $attributes->merge([
        'type' => 'radio',
        'class' => 'h-4 w-4 border-(--color-input-border) text-primary-600 focus:ring-primary-500',
    ]) }} />
    @if($label)
        <span class="text-sm text-(--color-on-surface)">{{ $label }}</span>
    @else
        {{ $slot }}
    @endif
</label>
