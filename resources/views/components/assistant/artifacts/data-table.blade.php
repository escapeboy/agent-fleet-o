@props(['payload' => [], 'open' => false])

@php
    $title = $payload['title'] ?? 'Data';
    $columns = $payload['columns'] ?? [];
    $rows = $payload['rows'] ?? [];
    $visibleRows = array_slice($rows, 0, 10);
    $hasMore = count($rows) > 10;
    $truncated = $payload['truncated'] ?? false;
@endphp

<x-assistant.artifacts.wrapper type="data_table" :title="$title" icon="table" :open="$open">
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

    @if($hasMore || $truncated)
        <p class="mt-2 text-[11px] text-gray-500">
            Showing {{ count($visibleRows) }} of {{ count($rows) }}{{ $truncated ? '+' : '' }} rows.
        </p>
    @endif

    <p class="sr-only">
        Data table with {{ count($visibleRows) }} rows and {{ count($columns) }} columns from tool {{ $payload['source_tool'] ?? 'unknown' }}.
    </p>
</x-assistant.artifacts.wrapper>
