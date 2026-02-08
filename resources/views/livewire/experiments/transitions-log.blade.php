<div>
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">From</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">To</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Reason</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Actor</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Time</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($transitions as $transition)
                    <tr>
                        <td class="px-4 py-3"><x-status-badge :status="$transition->from_state" /></td>
                        <td class="px-4 py-3"><x-status-badge :status="$transition->to_state" /></td>
                        <td class="px-4 py-3 max-w-xs truncate text-sm text-gray-600">{{ $transition->reason ?? '-' }}</td>
                        <td class="px-4 py-3 text-xs text-gray-500">{{ $transition->actor?->name ?? 'System' }}</td>
                        <td class="px-4 py-3 text-xs text-gray-500">{{ $transition->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400">No state transitions recorded.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
