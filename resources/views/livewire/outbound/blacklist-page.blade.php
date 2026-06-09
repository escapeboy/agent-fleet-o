<div>
    @if (session('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">{{ session('message') }}</div>
    @endif

    <div class="space-y-6">
        {{-- Add entry --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">Block a target</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    Blocked entries prevent outbound messages from being delivered. Matching is done on the
                    recipient email, its domain, the company, or keywords found in message content.
                </p>
            </div>
            <div class="grid grid-cols-1 gap-5 p-6 sm:grid-cols-3">
                <x-form-select wire:model="type" label="Type">
                    <option value="email">Email</option>
                    <option value="domain">Domain</option>
                    <option value="company">Company</option>
                    <option value="keyword">Keyword</option>
                </x-form-select>
                <x-form-input wire:model="value" label="Value" placeholder="spam@example.com" />
                <x-form-input wire:model="reason" label="Reason" placeholder="Optional" />
            </div>
            <div class="flex justify-end border-t border-gray-200 px-6 py-4">
                <button wire:click="add" wire:loading.attr="disabled"
                    class="rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                    Add to Blacklist
                </button>
            </div>
        </div>

        {{-- List --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                        <th class="px-6 py-3">Type</th>
                        <th class="px-6 py-3">Value</th>
                        <th class="px-6 py-3">Reason</th>
                        <th class="px-6 py-3">Added</th>
                        <th class="px-6 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($entries as $entry)
                        <tr class="hover:bg-gray-50" wire:key="entry-{{ $entry->id }}">
                            <td class="px-6 py-3">
                                <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{{ $entry->type }}</span>
                            </td>
                            <td class="px-6 py-3 font-mono text-gray-900">{{ $entry->value }}</td>
                            <td class="px-6 py-3 text-gray-500">{{ $entry->reason ?? '—' }}</td>
                            <td class="px-6 py-3 text-gray-500">{{ $entry->created_at?->diffForHumans() ?? '—' }}</td>
                            <td class="px-6 py-3 text-right">
                                <button wire:click="remove('{{ $entry->id }}')"
                                    wire:confirm="Remove this blacklist entry?"
                                    class="text-sm font-medium text-red-600 hover:text-red-800">Remove</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-8 text-center text-gray-400">No blacklist entries yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $entries->links() }}</div>
    </div>
</div>
