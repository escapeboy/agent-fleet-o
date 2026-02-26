<div>
    {{-- Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by name, email, phone or sender ID...">
                <x-slot:leadingIcon>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
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
            <svg class="mb-4 h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>
            </svg>
            <p class="mb-1 text-sm font-medium text-gray-900">No contacts yet</p>
            <p class="text-sm text-gray-500">Contacts are created automatically when approved senders send signals.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
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

        <div class="mt-4">
            {{ $contacts->links() }}
        </div>
    @endif
</div>
