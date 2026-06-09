<div>
    {{-- Filters --}}
    <form class="mb-6 flex flex-wrap items-center gap-4" onsubmit="return false">
        <x-form-select wire:model.live="period">
            <option value="24h">Last 24 hours</option>
            <option value="7d">Last 7 days</option>
            <option value="30d">Last 30 days</option>
            <option value="all">All time</option>
        </x-form-select>
    </form>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Captured</th>
                    <th class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Dataset</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Avg Score</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Pass Rate</th>
                    <th class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Sampled</th>
                    <th class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Active</th>
                    <th class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Deferred Passed</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($snapshots as $snapshot)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm text-gray-700" title="{{ $snapshot->created_at }}">{{ $snapshot->created_at->diffForHumans() }}</td>
                        <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">{{ $snapshot->dataset?->name ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-900">{{ $snapshot->avg_score !== null ? number_format($snapshot->avg_score, 2) : '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-700">{{ $snapshot->pass_rate !== null ? number_format($snapshot->pass_rate, 2).'%' : '—' }}</td>
                        <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">{{ $snapshot->sampled_count }}</td>
                        <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">{{ $snapshot->active_count }}</td>
                        <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">{{ $snapshot->deferred_passed }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-400">No monitor snapshots in this period.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $snapshots->links() }}
    </div>
</div>
