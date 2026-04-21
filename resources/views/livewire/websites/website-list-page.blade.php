<div>
    {{-- Toolbar --}}
    <form class="mb-6 flex flex-wrap items-center gap-4" onsubmit="return false">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search websites...">
                <x-slot:leadingIcon>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="statusFilter">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ ucfirst($status->value) }}</option>
            @endforeach
        </x-form-select>

        <a href="{{ route('websites.create') }}"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            New Website
        </a>
    </form>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full table-fixed divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        @php
                            $sortIcon = fn($field) => $sortField === $field
                                ? ($sortDirection === 'asc' ? '&#9650;' : '&#9660;')
                                : '<span class="text-gray-300">&#9650;</span>';
                        @endphp
                        <th wire:click="sortBy('name')" class="cursor-pointer px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                            Name {!! $sortIcon('name') !!}
                        </th>
                        <th wire:click="sortBy('status')" class="cursor-pointer px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                            Status {!! $sortIcon('status') !!}
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Pages</th>
                        <th class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Custom Domain</th>
                        <th wire:click="sortBy('created_at')" class="hidden md:table-cell cursor-pointer px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                            Created {!! $sortIcon('created_at') !!}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($websites as $website)
                        <tr class="transition hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <a href="{{ route('websites.show', $website) }}" class="font-medium text-primary-600 hover:text-primary-800">
                                    {{ $website->name }}
                                </a>
                                <p class="mt-0.5 text-xs text-gray-400">{{ $website->slug }}</p>
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $badgeColor = match($website->status) {
                                        \App\Domain\Website\Enums\WebsiteStatus::Draft => 'gray',
                                        \App\Domain\Website\Enums\WebsiteStatus::Generating => 'blue',
                                        \App\Domain\Website\Enums\WebsiteStatus::Published => 'green',
                                        \App\Domain\Website\Enums\WebsiteStatus::Archived => 'yellow',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-{{ $badgeColor }}-100 text-{{ $badgeColor }}-800">
                                    {{ ucfirst($website->status->value) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $website->pages_count }}</td>
                            <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">
                                {{ $website->custom_domain ?? '—' }}
                            </td>
                            <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">{{ $website->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-sm text-gray-400">
                                No websites found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $websites->links() }}
    </div>
</div>
