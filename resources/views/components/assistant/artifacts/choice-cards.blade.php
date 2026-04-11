@props(['payload' => [], 'open' => true, 'messageId' => null])

@php
    $question = $payload['question'] ?? '';
    $options = $payload['options'] ?? [];
@endphp

<x-assistant.artifacts.wrapper type="choice_cards" :title="$question" icon="cards" :open="$open">
    <div role="radiogroup" aria-label="{{ $question }}" class="grid gap-2 sm:grid-cols-{{ min(2, max(1, count($options))) }}">
        @foreach($options as $option)
            @php
                $action = $option['action'] ?? ['type' => 'dismiss'];
                $isDestructive = ($action['type'] ?? null) === 'invoke_tool' && ($action['destructive'] ?? false);
            @endphp
            <button
                type="button"
                wire:click="handleArtifactChoice('{{ $messageId }}', @js($option['value']))"
                @if($isDestructive)
                    wire:confirm="{{ $action['confirm_message'] ?? 'Are you sure?' }}"
                @endif
                class="flex flex-col items-start gap-1 rounded-lg border p-2.5 text-left text-xs transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 {{ $isDestructive ? 'border-amber-300 bg-amber-50 hover:border-amber-400' : 'border-gray-200 bg-white hover:border-indigo-400 hover:bg-indigo-50' }}"
            >
                <span class="font-medium text-gray-900">{{ $option['label'] }}</span>
                @if($option['description'] ?? null)
                    <span class="text-[11px] text-gray-500">{{ $option['description'] }}</span>
                @endif
                @if($isDestructive)
                    <span class="text-[10px] font-medium uppercase tracking-wide text-amber-700">Destructive</span>
                @endif
            </button>
        @endforeach
    </div>
</x-assistant.artifacts.wrapper>
