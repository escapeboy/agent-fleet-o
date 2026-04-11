@props([
    'type' => '',
    'title' => '',
    'icon' => 'default',
    'open' => false,
    'popoutPayload' => null,
])

@php
    $typeLabel = str_replace('_', ' ', $type);
    $hasPopout = $popoutPayload !== null;
@endphp

<details @if($open) open @endif
    class="mt-3 overflow-hidden rounded-xl border border-gray-200 bg-white open:bg-gray-50"
    @if($hasPopout) data-artifact-payload="{{ json_encode($popoutPayload, JSON_UNESCAPED_UNICODE) }}" @endif
>
    <summary class="flex cursor-pointer items-center gap-2 px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
        <svg class="h-3.5 w-3.5 flex-shrink-0 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            @switch($icon)
                @case('table')
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    @break
                @case('chart')
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>
                    @break
                @case('cards')
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                    @break
                @case('form')
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    @break
                @case('link')
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    @break
                @case('code')
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                    @break
                @default
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            @endswitch
        </svg>
        <span class="truncate">{{ $title ?: $typeLabel }}</span>
        <span class="ml-auto flex-shrink-0 rounded-full bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-600">{{ $typeLabel }}</span>
        @if($hasPopout)
            <button
                type="button"
                onclick="event.preventDefault(); event.stopPropagation(); const p = JSON.parse(this.closest('details').dataset.artifactPayload || '{}'); window.dispatchEvent(new CustomEvent('artifact-popout', { detail: { payload: p } }));"
                aria-label="Open in full-screen modal"
                title="Open in full-screen modal"
                class="flex-shrink-0 rounded p-0.5 text-gray-400 hover:bg-white hover:text-indigo-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
            >
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                </svg>
            </button>
        @endif
        <svg class="h-3 w-3 flex-shrink-0 text-gray-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
    </summary>
    <div class="border-t border-gray-200 p-3 overflow-x-auto">
        {{ $slot }}
    </div>
</details>
