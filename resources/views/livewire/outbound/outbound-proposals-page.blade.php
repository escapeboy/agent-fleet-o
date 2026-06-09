<div>
    <div class="space-y-6">
        <div class="rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-700">
            Proposals are reviewed and approved or rejected from the
            <a href="{{ route('approvals.index') }}" wire:navigate class="font-medium underline">Approval Inbox</a>.
            This page is read-only.
        </div>

        {{-- Status filter --}}
        <div class="flex flex-wrap gap-2">
            <button wire:click="$set('statusFilter', '')"
                @class([
                    'rounded-full px-3 py-1 text-sm font-medium',
                    'bg-primary-600 text-white' => $statusFilter === '',
                    'bg-gray-100 text-gray-600 hover:bg-gray-200' => $statusFilter !== '',
                ])>
                All
            </button>
            @foreach ($statuses as $status)
                <button wire:click="$set('statusFilter', '{{ $status->value }}')"
                    @class([
                        'rounded-full px-3 py-1 text-sm font-medium',
                        'bg-primary-600 text-white' => $statusFilter === $status->value,
                        'bg-gray-100 text-gray-600 hover:bg-gray-200' => $statusFilter !== $status->value,
                    ])>
                    {{ ucwords(str_replace('_', ' ', $status->value)) }}
                    <span class="ml-1 text-xs opacity-75">{{ $counts[$status->value] ?? 0 }}</span>
                </button>
            @endforeach
        </div>

        {{-- List --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                        <th class="px-6 py-3">Status</th>
                        <th class="px-6 py-3">Channel</th>
                        <th class="px-6 py-3">Target</th>
                        <th class="px-6 py-3">Risk</th>
                        <th class="px-6 py-3">Created</th>
                        <th class="px-6 py-3 text-right">Content</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($proposals as $proposal)
                        <tr class="hover:bg-gray-50" wire:key="proposal-{{ $proposal->id }}">
                            <td class="px-6 py-3">
                                <span @class([
                                    'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                    'bg-yellow-100 text-yellow-800' => $proposal->status === \App\Domain\Outbound\Enums\OutboundProposalStatus::PendingApproval,
                                    'bg-green-100 text-green-800' => $proposal->status === \App\Domain\Outbound\Enums\OutboundProposalStatus::Approved,
                                    'bg-red-100 text-red-800' => $proposal->status === \App\Domain\Outbound\Enums\OutboundProposalStatus::Rejected,
                                    'bg-gray-100 text-gray-600' => in_array($proposal->status, [\App\Domain\Outbound\Enums\OutboundProposalStatus::Expired, \App\Domain\Outbound\Enums\OutboundProposalStatus::Cancelled], true),
                                ])>{{ ucwords(str_replace('_', ' ', $proposal->status->value)) }}</span>
                            </td>
                            <td class="px-6 py-3 text-gray-700">{{ $proposal->channel->value }}</td>
                            <td class="px-6 py-3 font-mono text-gray-700">
                                {{ $proposal->target['email'] ?? $proposal->target['company'] ?? '—' }}
                            </td>
                            <td class="px-6 py-3 text-gray-500">{{ number_format((float) $proposal->risk_score, 2) }}</td>
                            <td class="px-6 py-3 text-gray-500">{{ $proposal->created_at?->diffForHumans() ?? '—' }}</td>
                            <td class="px-6 py-3 text-right">
                                <button wire:click="toggle('{{ $proposal->id }}')"
                                    class="text-sm font-medium text-primary-600 hover:text-primary-800">
                                    {{ $expandedId === $proposal->id ? 'Hide' : 'View' }}
                                </button>
                            </td>
                        </tr>
                        @if ($expandedId === $proposal->id)
                            <tr wire:key="proposal-detail-{{ $proposal->id }}">
                                <td colspan="6" class="bg-gray-50 px-6 py-4">
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div>
                                            <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Target</h3>
                                            <pre class="overflow-x-auto rounded-lg bg-white p-3 text-xs text-gray-700">{{ json_encode($proposal->target, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>
                                        <div>
                                            <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Content</h3>
                                            <pre class="overflow-x-auto rounded-lg bg-white p-3 text-xs text-gray-700">{{ json_encode($proposal->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>
                                    </div>
                                    @if ($proposal->experiment)
                                        <p class="mt-3 text-xs text-gray-500">
                                            From experiment:
                                            <a href="{{ route('experiments.show', $proposal->experiment) }}" wire:navigate
                                                class="font-medium text-primary-600 hover:text-primary-800">{{ $proposal->experiment->title ?? $proposal->experiment->id }}</a>
                                        </p>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="6" class="px-6 py-8 text-center text-gray-400">No outbound proposals.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $proposals->links() }}</div>
    </div>
</div>
