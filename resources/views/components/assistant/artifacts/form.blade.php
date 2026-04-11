@props(['payload' => [], 'open' => true, 'messageId' => null])

@php
    $title = $payload['title'] ?? 'Quick input';
    $description = $payload['description'] ?? null;
    $submitLabel = $payload['submit_label'] ?? 'Submit';
    $fields = $payload['fields'] ?? [];
@endphp

<x-assistant.artifacts.wrapper type="form" :title="$title" icon="form" :open="$open">
    @if($description)
        <p class="mb-3 text-xs text-gray-600">{{ $description }}</p>
    @endif

    <form
        wire:submit.prevent="handleArtifactFormSubmit('{{ $messageId }}')"
        class="space-y-3 text-xs"
    >
        @foreach($fields as $field)
            @php
                $name = $field['name'] ?? '';
                $type = $field['type'] ?? 'text';
                $label = $field['label'] ?? $name;
                $required = $field['required'] ?? false;
                $help = $field['help'] ?? null;
                $fieldModel = "artifactForms.{{ $messageId }}.{$name}";
            @endphp

            <div>
                <label class="mb-1 block font-medium text-gray-700">
                    {{ $label }}@if($required)<span class="text-red-500">*</span>@endif
                </label>

                @if($type === 'textarea')
                    <textarea wire:model="{{ $fieldModel }}" rows="2"
                        class="w-full rounded border border-gray-300 px-2 py-1 text-xs focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        @if($required) required @endif
                    ></textarea>
                @elseif($type === 'select')
                    <select wire:model="{{ $fieldModel }}"
                        class="w-full rounded border border-gray-300 px-2 py-1 text-xs focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        @if($required) required @endif
                    >
                        <option value="">—</option>
                        @foreach($field['options'] ?? [] as $opt)
                            <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                @elseif($type === 'checkbox' || $type === 'boolean')
                    <input type="checkbox" wire:model="{{ $fieldModel }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                @elseif($type === 'number')
                    <input type="number" wire:model="{{ $fieldModel }}"
                        @if(isset($field['min'])) min="{{ $field['min'] }}" @endif
                        @if(isset($field['max'])) max="{{ $field['max'] }}" @endif
                        class="w-full rounded border border-gray-300 px-2 py-1 text-xs focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        @if($required) required @endif
                    />
                @elseif($type === 'date')
                    <input type="date" wire:model="{{ $fieldModel }}"
                        class="w-full rounded border border-gray-300 px-2 py-1 text-xs focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        @if($required) required @endif
                    />
                @else
                    <input type="text" wire:model="{{ $fieldModel }}"
                        class="w-full rounded border border-gray-300 px-2 py-1 text-xs focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        @if($required) required @endif
                    />
                @endif

                @if($help)
                    <p class="mt-0.5 text-[10px] text-gray-500">{{ $help }}</p>
                @endif
            </div>
        @endforeach

        <button type="submit"
            class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
        >
            {{ $submitLabel }}
        </button>
    </form>
</x-assistant.artifacts.wrapper>
