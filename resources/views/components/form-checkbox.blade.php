@props([
    'label' => null,
])

<label class="inline-flex items-center gap-2">
    <input {{ $attributes->merge([
        'type' => 'checkbox',
        'class' => 'h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500',
    ]) }} />
    @if($label)
        <span class="text-sm text-gray-700">{{ $label }}</span>
    @else
        {{ $slot }}
    @endif
</label>
