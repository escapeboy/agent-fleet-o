@props([
    'context',
    'contextId' => null,
    'label' => 'Ask AI',
    'size' => 'sm',
])

@php
    $sizeClasses = match ($size) {
        'xs' => 'px-2 py-1 text-xs gap-1',
        'md' => 'px-3 py-2 text-sm gap-2',
        default => 'px-2.5 py-1.5 text-xs gap-1.5',
    };
@endphp

<button
    type="button"
    data-ask-ai-context="{{ $context }}"
    @if($contextId) data-ask-ai-context-id="{{ $contextId }}" @endif
    {{ $attributes->merge(['class' => 'inline-flex items-center rounded-lg border border-indigo-300 bg-indigo-50 font-medium text-indigo-700 hover:bg-indigo-100 transition-colors ' . $sizeClasses]) }}
    @click="
        Livewire.dispatch('assistant-open-with-context', {
            context: {{ Js::from($context) }},
            contextId: {{ Js::from($contextId) }}
        });
        window.dispatchEvent(new CustomEvent('open-assistant'));
    "
>
    <i class="fa-solid fa-wand-magic-sparkles text-[10px]"></i>
    {{ $label }}
</button>
