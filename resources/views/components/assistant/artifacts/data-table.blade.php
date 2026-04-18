@props(['payload' => [], 'open' => false])

@php
    $title = $payload['title'] ?? 'Data';
    $columns = $payload['columns'] ?? [];
    $rows = $payload['rows'] ?? [];
    $visibleRows = array_slice($rows, 0, 10);
    $hasMore = count($rows) > 10;
    $truncated = $payload['truncated'] ?? false;
    // Only expose the pop-out when there is actually extra content to surface.
    $needsPopout = $hasMore || $truncated;
@endphp

<x-assistant.artifacts.wrapper
    type="data_table"
    :title="$title"
    icon="table"
    :open="$open"
    :popoutPayload="$needsPopout ? $payload : null"
>
    <table class="w-full text-left text-xs" role="table" aria-label="{{ $title }}">
        <thead class="border-b border-gray-200 text-gray-500">
            <tr>
                @foreach($columns as $col)
                    <th scope="col" class="pb-1.5 pr-3 font-medium">{{ $col['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($visibleRows as $row)
                <tr class="text-gray-700">
                    @foreach($columns as $col)
                        <td class="py-1.5 pr-3">{{ $row[$col['key']] ?? '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ max(1, count($columns)) }}" class="py-2 text-center text-gray-400">No rows.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if($needsPopout)
        <p class="mt-2 flex items-center justify-between text-[11px] text-gray-500">
            <span>Showing {{ count($visibleRows) }} of {{ count($rows) }}{{ $truncated ? '+' : '' }} rows.</span>
            <button
                type="button"
                onclick="window.dispatchEvent(new CustomEvent('artifact-popout', { detail: { payload: {{ json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) }} } }));"
                class="font-medium text-indigo-600 hover:text-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
            >
                Show all {{ count($rows) }} →
            </button>
        </p>
    @endif

    <p class="sr-only">
        Data table with {{ count($visibleRows) }} rows and {{ count($columns) }} columns from tool {{ $payload['source_tool'] ?? 'unknown' }}.
    </p>
</x-assistant.artifacts.wrapper>
