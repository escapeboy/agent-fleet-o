<div>
    @if (session('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">{{ session('message') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-700">{{ session('error') }}</div>
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
        $status = $broadcast->status->value;
    @endphp

    <div class="space-y-6">
        {{-- Header --}}
        <div class="rounded-xl border border-gray-200 bg-white px-6 py-4">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-gray-900">{{ $broadcast->name }}</h2>
                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusStyles[$status] ?? 'bg-gray-100 text-gray-600' }}">
                    {{ str_replace('_', ' ', $status) }}
                </span>
            </div>
            <p class="mt-1 text-sm text-gray-500">
                <span class="font-medium">Subject:</span> {{ $broadcast->subject }} ·
                {{ $broadcast->recipient_count }} recipient(s)
                @if ($broadcast->sent_at) · sent {{ $broadcast->sent_at->diffForHumans() }} @endif
            </p>
            <a href="{{ route('audiences.show', $broadcast->audience_id) }}" wire:navigate
                class="mt-2 inline-block text-sm text-primary-600 hover:text-primary-800">← Back to audience</a>
        </div>

        {{-- Actions --}}
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 bg-white px-6 py-4">
            @if ($status === 'draft')
                <button wire:click="requestApproval" wire:loading.attr="disabled"
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                    Submit for Approval
                </button>
            @elseif ($status === 'pending_approval')
                <button wire:click="approve" wire:loading.attr="disabled"
                    class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50">
                    Approve &amp; Send
                </button>
            @endif

            @if (in_array($status, ['draft', 'pending_approval'], true))
                <button wire:click="cancel" wire:loading.attr="disabled"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                    Cancel
                </button>
            @else
                <span class="text-sm text-gray-400">No actions available for this status.</span>
            @endif
        </div>

        {{-- Recipients --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">Recipients</h2>
            </div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <tbody class="divide-y divide-gray-100">
                    @forelse ($recipients as $recipient)
                        <tr>
                            <td class="px-6 py-3 text-gray-700">{{ $recipient->email }}</td>
                            <td class="px-6 py-3 text-right">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium
                                    @class([
                                        'bg-green-100 text-green-700' => $recipient->status->value === 'sent',
                                        'bg-red-100 text-red-700' => in_array($recipient->status->value, ['failed', 'bounced'], true),
                                        'bg-gray-100 text-gray-600' => $recipient->status->value === 'pending',
                                    ])">
                                    {{ $recipient->status->value }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="px-6 py-8 text-center text-gray-400">
                            No recipients yet — they are materialized when the broadcast is approved.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
