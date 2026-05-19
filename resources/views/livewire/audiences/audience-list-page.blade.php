<div>
    @if (session('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">{{ session('message') }}</div>
    @endif

    <div class="space-y-6">
        {{-- Create --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">New Audience</h2>
                <p class="mt-0.5 text-sm text-gray-500">A team-scoped list of contacts you can broadcast to.</p>
            </div>
            <div class="grid grid-cols-1 gap-5 p-6 sm:grid-cols-3">
                <x-form-input wire:model="name" label="Name" placeholder="Newsletter subscribers" />
                <x-form-input wire:model="topic" label="Topic" placeholder="newsletter" />
                <x-form-input wire:model="description" label="Description" placeholder="Optional" />
            </div>
            <div class="flex justify-end border-t border-gray-200 px-6 py-4">
                <button wire:click="create" wire:loading.attr="disabled"
                    class="rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                    Create Audience
                </button>
            </div>
        </div>

        {{-- List --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                        <th class="px-6 py-3">Name</th>
                        <th class="px-6 py-3">Topic</th>
                        <th class="px-6 py-3">Members</th>
                        <th class="px-6 py-3">Subscribed</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($audiences as $audience)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3">
                                <a href="{{ route('audiences.show', $audience) }}" wire:navigate
                                    class="font-medium text-primary-600 hover:text-primary-800">{{ $audience->name }}</a>
                            </td>
                            <td class="px-6 py-3 text-gray-500">{{ $audience->topic ?? '—' }}</td>
                            <td class="px-6 py-3 text-gray-700">{{ $audience->members_count }}</td>
                            <td class="px-6 py-3 text-gray-700">{{ $audience->subscribed_members_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-8 text-center text-gray-400">No audiences yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $audiences->links() }}</div>
    </div>
</div>
