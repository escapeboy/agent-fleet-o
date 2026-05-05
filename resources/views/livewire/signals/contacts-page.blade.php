<div>
    {{-- Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by name, email, phone or sender ID...">
                <x-slot:leadingIcon>
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base text-gray-400"></i>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

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

    {{-- Empty state --}}
    @if($contacts->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white py-16">
            <i class="fa-solid fa-users mb-4 text-4xl text-gray-400"></i>
            <p class="mb-1 text-sm font-medium text-gray-900">No contacts yet</p>
            <p class="text-sm text-gray-500">Contacts are created automatically when approved senders send signals.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Contact</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Channels</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Phone / Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Last seen</th>
                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($contacts as $contact)
                        <tr class="transition hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <p class="font-medium text-gray-900">{{ $contact->display_name ?? '—' }}</p>
                                <p class="mt-0.5 font-mono text-xs text-gray-400">{{ Str::limit($contact->id, 14) }}</p>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-wrap gap-1">
                                    @foreach($contact->channels as $ch)
                                        @php
                                            $colors = ['telegram'=>'bg-blue-100 text-blue-800','whatsapp'=>'bg-green-100 text-green-800','discord'=>'bg-indigo-100 text-indigo-800','slack'=>'bg-yellow-100 text-yellow-800','matrix'=>'bg-teal-100 text-teal-800'];
                                            $color = $colors[$ch->channel] ?? 'bg-gray-100 text-gray-800';
                                        @endphp
                                        <span title="{{ $ch->external_username ?? $ch->external_id }}"
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $color }}">
                                            {{ ucfirst($ch->channel) }}
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">
                                @if($contact->phone)
                                    <p>{{ $contact->phone }}</p>
                                @endif
                                @if($contact->email)
                                    <p class="text-gray-400">{{ $contact->email }}</p>
                                @endif
                                @if(!$contact->phone && !$contact->email)
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                {{ $contact->updated_at->diffForHumans() }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('contacts.show', $contact) }}"
                                    class="rounded px-2.5 py-1 text-xs font-medium text-indigo-600 hover:bg-indigo-50">
                                    View
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>

        <div class="mt-4">
            {{ $contacts->links() }}
        </div>
    @endif
</div>
