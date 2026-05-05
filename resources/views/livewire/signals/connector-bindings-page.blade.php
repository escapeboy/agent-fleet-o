<div>
    {{-- Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by sender ID or name...">
                <x-slot:leadingIcon>
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 text-base -translate-y-1/2 text-gray-400"></i>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <select wire:model.live="statusFilter"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="blocked">Blocked</option>
        </select>

        @if($channels->count() > 1)
            <select wire:model.live="channelFilter"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                <option value="">All channels</option>
                @foreach($channels as $ch)
                    <option value="{{ $ch }}">{{ ucfirst($ch) }}</option>
                @endforeach
            </select>
        @endif
    </div>

    {{-- Info banner --}}
    <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
        <strong>Sender Allowlist (DM Pairing)</strong> — Unknown senders on gated channels (Telegram, WhatsApp, Discord)
        are held here with a pairing code. Approve them to allow their messages to create signals.
    </div>

    {{-- Empty state --}}
    @if($bindings->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white py-16">
            <i class="fa-solid fa-shield-halved mb-4 text-5xl text-gray-400"></i>
            <p class="mb-1 text-sm font-medium text-gray-900">No connector bindings yet</p>
            <p class="text-sm text-gray-500">When unknown senders message your bots, they'll appear here for approval.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Channel</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Sender</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Pairing Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">First seen</th>
                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($bindings as $binding)
                        <tr class="transition hover:bg-gray-50">
                            <td class="px-6 py-4">
                                @php
                                    $channelColors = [
                                        'telegram' => 'bg-blue-100 text-blue-800',
                                        'whatsapp' => 'bg-green-100 text-green-800',
                                        'discord'  => 'bg-indigo-100 text-indigo-800',
                                        'signal_protocol' => 'bg-purple-100 text-purple-800',
                                        'matrix'   => 'bg-teal-100 text-teal-800',
                                    ];
                                    $channelColor = $channelColors[$binding->channel] ?? 'bg-gray-100 text-gray-800';
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $channelColor }}">
                                    {{ ucfirst(str_replace('_', ' ', $binding->channel)) }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <p class="font-medium text-gray-900">{{ $binding->external_name ?? $binding->external_id }}</p>
                                @if($binding->external_name)
                                    <p class="mt-0.5 text-xs text-gray-400">{{ $binding->external_id }}</p>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $statusColors = [
                                        'pending'  => 'bg-yellow-100 text-yellow-800',
                                        'approved' => 'bg-green-100 text-green-800',
                                        'blocked'  => 'bg-red-100 text-red-800',
                                    ];
                                    $statusColor = $statusColors[$binding->status->value] ?? 'bg-gray-100 text-gray-700';
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusColor }}">
                                    {{ $binding->status->label() }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm font-mono text-gray-600">
                                @if($binding->isPending() && $binding->pairing_code)
                                    <span class="{{ $binding->isPairingCodeExpired() ? 'text-red-400 line-through' : 'text-gray-800' }}">
                                        {{ $binding->pairing_code }}
                                    </span>
                                    @if($binding->isPairingCodeExpired())
                                        <span class="ml-1 text-xs text-red-400">(expired)</span>
                                    @endif
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                {{ $binding->created_at->diffForHumans() }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex justify-end gap-2">
                                    @if($binding->isPending())
                                        <button wire:click="approve('{{ $binding->id }}')"
                                            wire:loading.attr="disabled"
                                            class="rounded px-2.5 py-1 text-xs font-medium text-green-700 hover:bg-green-50">
                                            Approve
                                        </button>
                                        <button wire:click="block('{{ $binding->id }}')"
                                            class="rounded px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50">
                                            Block
                                        </button>
                                    @elseif($binding->isApproved())
                                        <button wire:click="block('{{ $binding->id }}')"
                                            class="rounded px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100">
                                            Block
                                        </button>
                                    @elseif($binding->isBlocked())
                                        <button wire:click="approve('{{ $binding->id }}')"
                                            class="rounded px-2.5 py-1 text-xs font-medium text-green-700 hover:bg-green-50">
                                            Unblock
                                        </button>
                                    @endif
                                    <button wire:click="delete('{{ $binding->id }}')"
                                        wire:confirm="Delete this binding?"
                                        class="rounded px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>

        <div class="mt-4">
            {{ $bindings->links() }}
        </div>
    @endif
</div>
