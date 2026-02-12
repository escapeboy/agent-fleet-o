<div>
    {{-- Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search credentials..." class="pl-10">
                <x-slot:leadingIcon>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="typeFilter">
            <option value="">All Types</option>
            @foreach($types as $type)
                <option value="{{ $type->value }}">{{ $type->label() }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="statusFilter">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </x-form-select>

        <a href="{{ route('credentials.create') }}"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            New Credential
        </a>
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200">
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
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                    <th wire:click="sortBy('status')" class="cursor-pointer px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                        Status {!! $sortIcon('status') !!}
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Expires</th>
                    <th wire:click="sortBy('last_used_at')" class="cursor-pointer px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                        Last Used {!! $sortIcon('last_used_at') !!}
                    </th>
                    <th wire:click="sortBy('created_at')" class="cursor-pointer px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                        Created {!! $sortIcon('created_at') !!}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($credentials as $credential)
                    <tr class="transition hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <a href="{{ route('credentials.show', $credential) }}" class="font-medium text-primary-600 hover:text-primary-800">
                                {{ $credential->name }}
                            </a>
                            @if($credential->description)
                                <p class="mt-0.5 max-w-xs truncate text-xs text-gray-400">{{ $credential->description }}</p>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $credential->credential_type->color() }}">
                                {{ $credential->credential_type->label() }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <x-status-badge :status="$credential->status->value" />
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            @if($credential->expires_at)
                                <span class="{{ $credential->isExpired() ? 'text-red-600 font-medium' : '' }}">
                                    {{ $credential->expires_at->format('M j, Y') }}
                                </span>
                            @else
                                <span class="text-gray-300">--</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            {{ $credential->last_used_at?->diffForHumans() ?? '--' }}
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $credential->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-400">
                            No credentials found. Create your first one!
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $credentials->links() }}
    </div>
</div>
