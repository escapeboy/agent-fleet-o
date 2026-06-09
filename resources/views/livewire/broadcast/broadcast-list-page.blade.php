<div>
    @if (session('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">{{ session('message') }}</div>
    @endif

    @php
        $statusStyles = [
            'draft' => 'bg-gray-100 text-gray-600',
            'pending_approval' => 'bg-amber-100 text-amber-700',
            'approved' => 'bg-blue-100 text-blue-700',
            'sending' => 'bg-blue-100 text-blue-700',
            'sent' => 'bg-green-100 text-green-700',
            'failed' => 'bg-red-100 text-red-700',
            'cancelled' => 'bg-gray-100 text-gray-500',
        ];
    @endphp

    <div class="space-y-6">
        <div class="flex justify-end">
            <a href="{{ route('broadcasts.create') }}" wire:navigate
                class="rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700">
                New Broadcast
            </a>
        </div>

        {{-- List --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                        <th class="px-6 py-3">Name</th>
                        <th class="px-6 py-3">Status</th>
                        <th class="px-6 py-3">Audience</th>
                        <th class="px-6 py-3">Recipients</th>
                        <th class="px-6 py-3">Sent</th>
                        <th class="px-6 py-3">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($broadcasts as $broadcast)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3">
                                <a href="{{ route('broadcasts.show', $broadcast) }}" wire:navigate
                                    class="font-medium text-primary-600 hover:text-primary-800">{{ $broadcast->name }}</a>
                            </td>
                            <td class="px-6 py-3">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusStyles[$broadcast->status->value] ?? 'bg-gray-100 text-gray-600' }}">
                                    {{ str_replace('_', ' ', $broadcast->status->value) }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-gray-700">{{ $broadcast->audience?->name ?? '—' }}</td>
                            <td class="px-6 py-3 text-gray-700">{{ $broadcast->recipient_count }}</td>
                            <td class="px-6 py-3 text-gray-500">
                                {{ $broadcast->sent_at?->diffForHumans() ?? '—' }}
                            </td>
                            <td class="px-6 py-3 text-gray-500">{{ $broadcast->created_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-8 text-center text-gray-400">No broadcasts yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $broadcasts->links() }}</div>
    </div>
</div>
