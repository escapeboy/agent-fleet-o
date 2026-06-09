<div>
    {{-- Filters --}}
    <form class="mb-6 flex flex-wrap items-center gap-4" onsubmit="return false">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search goal or outcome..." class="pl-10">
                <x-slot:leadingIcon>
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base text-gray-400"></i>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>
    </form>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Goal</th>
                    <th class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Outcome</th>
                    <th class="hidden lg:table-cell px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Source Experiment</th>
                    <th class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Recorded</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($entries as $entry)
                    <tr class="hover:bg-gray-50 cursor-pointer" wire:click="toggleExpand('{{ $entry->id }}')">
                        <td class="px-6 py-4 text-sm text-gray-900 max-w-md truncate">{{ $entry->goal_text }}</td>
                        <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500 max-w-md truncate">{{ $entry->outcome_summary }}</td>
                        <td class="hidden lg:table-cell px-6 py-4 text-sm text-gray-500">
                            @if($entry->experiment)
                                <a href="{{ route('experiments.show', $entry->experiment_id) }}" class="text-primary-600 hover:underline" wire:click.stop>
                                    {{ $entry->experiment->title }}
                                </a>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">{{ $entry->created_at->diffForHumans() }}</td>
                        <td class="px-6 py-4 text-sm text-gray-400">
                            <i class="fa-solid fa-chevron-right text-base transition {{ $expandedId === $entry->id ? 'rotate-90' : '' }}"></i>
                        </td>
                    </tr>

                    @if($expandedId === $entry->id)
                        <tr>
                            <td colspan="5" class="bg-gray-50 px-6 py-4">
                                <div class="mb-3">
                                    <p class="text-xs font-semibold text-gray-500 uppercase">Goal</p>
                                    <p class="mt-1 text-sm text-gray-800">{{ $entry->goal_text }}</p>
                                </div>
                                <div class="mb-3">
                                    <p class="text-xs font-semibold text-gray-500 uppercase">Outcome Summary</p>
                                    <p class="mt-1 text-sm text-gray-800">{{ $entry->outcome_summary }}</p>
                                </div>
                                @if(! empty($entry->tool_sequence))
                                    <div>
                                        <p class="text-xs font-semibold text-gray-500 uppercase">Tool Sequence</p>
                                        <div class="mt-1 flex flex-wrap gap-1.5">
                                            @foreach($entry->tool_sequence as $tool)
                                                <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">{{ is_string($tool) ? $tool : json_encode($tool) }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center text-sm text-gray-400">No reasoning bank entries yet. Lessons are distilled automatically as experiments complete.</td>
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
