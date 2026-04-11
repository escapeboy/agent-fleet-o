@props(['payload' => [], 'open' => false])

@php
    $title = $payload['title'] ?? 'Related links';
    $items = $payload['items'] ?? [];
@endphp

<x-assistant.artifacts.wrapper type="link_list" :title="$title" icon="link" :open="$open">
    <ul class="space-y-1.5 text-xs">
        @foreach($items as $item)
            <li>
                <a href="{{ $item['url'] }}"
                    target="_blank"
                    rel="noopener noreferrer nofollow"
                    class="group flex flex-col gap-0.5 rounded p-1.5 hover:bg-indigo-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                >
                    <span class="font-medium text-indigo-600 group-hover:text-indigo-700">{{ $item['label'] }}</span>
                    @if($item['description'] ?? null)
                        <span class="text-[11px] text-gray-500">{{ $item['description'] }}</span>
                    @endif
                    <span class="truncate text-[10px] font-mono text-gray-400">{{ $item['url'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</x-assistant.artifacts.wrapper>
