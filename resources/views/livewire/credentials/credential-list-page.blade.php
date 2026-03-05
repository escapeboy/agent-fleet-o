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

        @if($canCreate)
            <a href="{{ route('credentials.create') }}"
                class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                New Credential
            </a>
        @else
            <span class="rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-400 cursor-not-allowed" title="Plan limit reached">
                New Credential
            </span>
        @endif
    </div>

    {{-- Encryption Info --}}
    @if($team)
        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-4">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 flex-shrink-0">
                    <svg class="h-5 w-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <div class="flex-1">
                    <h4 class="text-sm font-semibold text-gray-900">Encryption</h4>
                    @if($kmsConfig && $kmsConfig->status->value === 'active')
                        <p class="mt-0.5 text-sm text-gray-600">
                            All credentials are encrypted with per-team envelope encryption (XSalsa20-Poly1305).
                            Your data encryption key is protected by <span class="font-medium text-green-700">{{ $kmsConfig->provider->label() }}</span> (customer-managed).
                        </p>
                    @else
                        <p class="mt-0.5 text-sm text-gray-600">
                            All credentials are encrypted with per-team envelope encryption (XSalsa20-Poly1305).
                            Each team has a unique data encryption key (DEK) that wraps all stored secrets.
                        </p>
                        <x-plan-gate feature="customer_managed_keys" required-plan="Pro" mode="inline"
                            upgrade-message="Bring your own encryption keys (AWS KMS, GCP Cloud KMS, or Azure Key Vault).">
                            <p class="mt-1.5 text-sm text-gray-600">
                                <svg class="mr-1 inline h-4 w-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                Connect your own KMS provider to wrap your team's data encryption key.
                            </p>
                        </x-plan-gate>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
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
    </div>

    <div class="mt-4">
        {{ $credentials->links() }}
    </div>
</div>
