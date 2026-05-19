<div>
    @if (session('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">{{ session('message') }}</div>
    @endif

    <div class="space-y-6">
        {{-- Header --}}
        <div class="rounded-xl border border-gray-200 bg-white px-6 py-4">
            <h2 class="text-base font-semibold text-gray-900">{{ $audience->name }}</h2>
            <p class="mt-0.5 text-sm text-gray-500">
                @if ($audience->topic)<span class="font-medium">Topic:</span> {{ $audience->topic }} · @endif
                {{ $audience->description ?? 'No description.' }}
            </p>
            <a href="{{ route('audiences.index') }}" wire:navigate
                class="mt-2 inline-block text-sm text-primary-600 hover:text-primary-800">← All audiences</a>
        </div>

        {{-- Members --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">Members</h2>
            </div>
            <div class="flex items-end gap-3 p-6">
                <div class="flex-1">
                    <x-form-input wire:model="memberEmail" type="email" label="Add contact by email"
                        placeholder="person@example.com" />
                </div>
                <button wire:click="addMember" wire:loading.attr="disabled"
                    class="rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                    Add
                </button>
            </div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <tbody class="divide-y divide-gray-100">
                    @forelse ($members as $member)
                        <tr>
                            <td class="px-6 py-3 text-gray-700">{{ $member->contactIdentity?->email ?? '—' }}</td>
                            <td class="px-6 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ $member->status->value === 'subscribed' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                    {{ $member->status->value }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-right">
                                @if ($member->status->value === 'subscribed' && $member->contact_identity_id)
                                    <button wire:click="unsubscribe('{{ $member->contact_identity_id }}')"
                                        class="text-xs font-medium text-red-600 hover:text-red-800">Unsubscribe</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-6 py-8 text-center text-gray-400">No members yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Broadcasts --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">Broadcasts</h2>
                <p class="mt-0.5 text-sm text-gray-500">A broadcast emails every subscribed member after approval.</p>
            </div>
            <div class="grid grid-cols-1 gap-5 p-6 sm:grid-cols-2">
                <x-form-input wire:model="broadcastName" label="Name" placeholder="May newsletter" />
                <x-form-input wire:model="broadcastSubject" label="Subject" placeholder="What's new this month" />
                <div class="sm:col-span-2">
                    <x-form-textarea wire:model="broadcastBody" label="Body (HTML)" rows="5"
                        placeholder="<p>Hello…</p>" />
                </div>
            </div>
            <div class="flex justify-end border-t border-gray-200 px-6 py-4">
                <button wire:click="createBroadcast" wire:loading.attr="disabled"
                    class="rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                    Create Broadcast
                </button>
            </div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <tbody class="divide-y divide-gray-100">
                    @forelse ($broadcasts as $broadcast)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3">
                                <a href="{{ route('broadcasts.show', $broadcast) }}" wire:navigate
                                    class="font-medium text-primary-600 hover:text-primary-800">{{ $broadcast->name }}</a>
                            </td>
                            <td class="px-6 py-3 text-gray-500">{{ $broadcast->subject }}</td>
                            <td class="px-6 py-3 text-gray-600">{{ $broadcast->status->value }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-6 py-6 text-center text-gray-400">No broadcasts yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
