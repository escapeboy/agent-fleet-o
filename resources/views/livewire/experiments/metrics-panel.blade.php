<div>
    {{-- Summary Cards --}}
    @if($summary->isNotEmpty())
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($summary as $type => $stats)
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <p class="text-xs font-medium uppercase tracking-wider text-gray-500">{{ str_replace('_', ' ', $type) }}</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ $stats['avg'] }}</p>
                    <div class="mt-2 flex gap-4 text-xs text-gray-500">
                        <span>Count: {{ $stats['count'] }}</span>
                        <span>Sum: {{ $stats['sum'] }}</span>
                        <span>Range: {{ $stats['min'] }}-{{ $stats['max'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Metrics Table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Value</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Source</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Occurred</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($metrics as $metric)
                    <tr>
                        <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $metric->type }}</td>
                        <td class="px-4 py-3 text-sm text-gray-700">{{ number_format($metric->value, 4) }}</td>
                        <td class="px-4 py-3 text-xs text-gray-500">{{ $metric->source }}</td>
                        <td class="px-4 py-3 text-xs text-gray-500">{{ $metric->occurred_at?->diffForHumans() ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-400">No metrics collected yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
