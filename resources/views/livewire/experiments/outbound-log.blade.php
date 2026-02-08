<div>
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Channel</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Risk</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Created</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($proposals as $proposal)
                    <tr>
                        <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ ucfirst($proposal->channel->value) }}</td>
                        <td class="px-4 py-3">
                            <x-status-badge :status="$proposal->status->value" />
                        </td>
                        <td class="px-4 py-3">
                            @php $riskColor = $proposal->risk_score > 0.7 ? 'text-red-600' : ($proposal->risk_score > 0.4 ? 'text-yellow-600' : 'text-green-600'); @endphp
                            <span class="text-sm font-medium {{ $riskColor }}">{{ number_format($proposal->risk_score, 2) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            @foreach($proposal->outboundActions as $action)
                                <x-status-badge :status="$action->status->value" />
                            @endforeach
                            @if($proposal->outboundActions->isEmpty())
                                <span class="text-xs text-gray-400">None</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500">{{ $proposal->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400">No outbound proposals yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
