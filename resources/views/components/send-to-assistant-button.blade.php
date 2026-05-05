@props(['message', 'label' => 'Ask assistant'])
<button
    type="button"
    class="inline-flex items-center gap-1.5 text-xs text-gray-500 hover:text-primary-600 transition-colors"
    @click="window.dispatchEvent(new CustomEvent('open-assistant', { detail: { message: {{ Js::from($message) }} } }))"
>
    <i class="fa-solid fa-comment text-sm"></i>
    {{ $label }}
</button>
