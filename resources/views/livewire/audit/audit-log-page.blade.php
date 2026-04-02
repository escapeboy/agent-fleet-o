<div>
    {{-- Filters --}}
    <form class="mb-6 flex flex-wrap items-center gap-4" onsubmit="return false" toolname="search_audit_log" tooldescription="Filter audit log entries by event type and search query">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search events or users..." class="pl-10" toolparamdescription="Free-text search across audit log descriptions and subjects">
                <x-slot:leadingIcon>
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base text-gray-400"></i>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="eventFilter" toolparamdescription="Filter by audit event category">
            <option value="">All Categories</option>
            @foreach($eventTypes as $type)
                <option value="{{ $type }}">{{ ucfirst($type) }}</option>
            @endforeach
        </x-form-select>
    </form>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Event</th>
                    <th class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Subject</th>
                    <th class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Triggered By</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">User</th>
                    <th class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Time</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($entries as $entry)
                    <tr class="hover:bg-gray-50 cursor-pointer" wire:click="toggleEntry('{{ $entry->id }}')">
                        <td class="px-6 py-4 text-sm">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                @if(str_starts_with($entry->event, 'experiment.')) bg-blue-100 text-blue-800
                                @elseif(str_starts_with($entry->event, 'approval.')) bg-purple-100 text-purple-800
                                @elseif(str_starts_with($entry->event, 'budget.')) bg-yellow-100 text-yellow-800
                                @elseif(str_starts_with($entry->event, 'agent.')) bg-green-100 text-green-800
                                @else bg-gray-100 text-gray-800
                                @endif">
                                {{ $entry->event }}
                            </span>
                        </td>
                        <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">{{ $entry->subject_type ? class_basename($entry->subject_type) : '-' }}</td>
                        <td class="hidden md:table-cell px-6 py-4 text-sm">
                            @if($entry->triggered_by)
                                @php
                                    [$triggerType, $triggerId] = array_pad(explode(':', $entry->triggered_by, 2), 2, null);
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ $triggerType === 'agent' ? 'bg-green-100 text-green-700' : ($triggerType === 'scheduler' ? 'bg-blue-100 text-blue-700' : ($triggerType === 'api' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-700')) }}">
                                    {{ ucfirst($triggerType ?? $entry->triggered_by) }}
                                </span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $entry->user?->name ?? 'System' }}</td>
                        <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">{{ $entry->created_at->diffForHumans() }}</td>
                        <td class="px-6 py-4 text-sm text-gray-400">
                            @if($entry->properties || $entry->decision_context)
                                <i class="fa-solid fa-chevron-right text-base transition {{ $expandedEntryId === $entry->id ? 'rotate-90' : '' }}"></i>
                            @endif
                        </td>
                    </tr>

                    @if($expandedEntryId === $entry->id && ($entry->properties || $entry->decision_context))
                        <tr>
                            <td colspan="6" class="bg-gray-50 px-6 py-4">
                                @if($entry->decision_context)
                                    <div class="mb-3 rounded-lg border border-blue-100 bg-blue-50 p-3">
                                        <p class="text-xs font-semibold text-blue-700">Decision Context</p>
                                        <p class="mt-1 text-sm text-blue-800">{{ $entry->decision_context }}</p>
                                    </div>
                                @endif
                                @if($entry->properties)
                                    <div class="rounded-lg bg-gray-900 p-4">
                                        <pre class="overflow-auto text-xs text-green-400">{{ json_encode($entry->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-400">No audit entries yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $entries->links() }}
    </div>
</div>
