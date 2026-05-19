<div>
    {{-- Flash messages --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif
    @if(session()->has('error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    {{-- Tier filter tabs --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <button wire:click="$set('tierFilter', '')"
            class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium transition
                {{ $tierFilter === '' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
            All
        </button>
        @foreach($tiers as $tier)
            <button wire:click="$set('tierFilter', '{{ $tier->value }}')"
                class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-medium transition
                    {{ $tierFilter === $tier->value ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                {{ ucfirst($tier->value) }}
                @if($tier->value === 'proposed' && $proposalCount > 0)
                    <span class="ml-1 rounded-full bg-amber-500 px-1.5 py-0.5 text-xs font-bold text-white">
                        {{ $proposalCount }}
                    </span>
                @endif
            </button>
        @endforeach

        @if($conflictCount > 0)
            <button wire:click="$set('conflictFilter', '{{ $conflictFilter === '1' ? '' : '1' }}')"
                class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-medium transition
                    {{ $conflictFilter === '1' ? 'bg-red-600 text-white' : 'bg-red-50 text-red-700 hover:bg-red-100' }}">
                <i class="fa-solid fa-triangle-exclamation"></i> Conflicts
                <span class="ml-1 rounded-full bg-red-500 px-1.5 py-0.5 text-xs font-bold text-white">{{ $conflictCount }}</span>
            </button>
        @endif
    </div>

    {{-- Filters --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search memory content..." class="pl-10">
                <x-slot:leadingIcon>
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 text-base -translate-y-1/2 text-gray-400"></i>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="agentFilter">
            <option value="">All Agents</option>
            @foreach($agents as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="projectFilter">
            <option value="">All Projects</option>
            @foreach($projects as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="sourceTypeFilter">
            <option value="">All Sources</option>
            @foreach($sourceTypes as $type)
                <option value="{{ $type }}">{{ ucfirst($type) }}</option>
            @endforeach
        </x-form-select>

        @if($availableTags->isNotEmpty())
            <x-form-select wire:model.live="tagFilter">
                <option value="">All Tags</option>
                @foreach($availableTags as $tag)
                    <option value="{{ $tag }}">{{ $tag }}</option>
                @endforeach
            </x-form-select>
        @endif

        <x-form-select wire:model.live="beliefTypeFilter">
            <option value="">All Belief Types</option>
            @foreach($beliefTypes as $bt)
                <option value="{{ $bt->value }}">{{ Str::headline($bt->value) }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="beliefStatusFilter">
            <option value="">All Belief Statuses</option>
            @foreach($beliefStatuses as $bs)
                <option value="{{ $bs->value }}">{{ ucfirst($bs->value) }}</option>
            @endforeach
        </x-form-select>

        @if($domains->isNotEmpty())
            <x-form-select wire:model.live="domainFilter">
                <option value="">All Domains</option>
                @foreach($domains as $domain)
                    <option value="{{ $domain }}">{{ $domain }}</option>
                @endforeach
            </x-form-select>
        @endif
    </div>

    {{-- Knowledge Upload --}}
    <div class="mb-6">
        <livewire:memory.knowledge-upload-panel />
    </div>

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
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Agent</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Content</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Tier</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Belief</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Source</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Project</th>
                    <th wire:click="sortBy('created_at')" class="cursor-pointer px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                        Created {!! $sortIcon('created_at') !!}
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($memories as $memory)
                    <tr class="cursor-pointer transition hover:bg-gray-50" wire:click="toggleExpand('{{ $memory->id }}')">
                        <td class="px-6 py-4 text-sm font-medium text-primary-600">
                            {{ $memory->agent?->name ?? '-' }}
                        </td>
                        <td class="max-w-xs truncate px-6 py-4 text-sm text-gray-500">
                            @if($memory->conflict_flag)
                                <span class="mr-1 inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                                    <i class="fa-solid fa-triangle-exclamation mr-1"></i>conflict
                                </span>
                            @endif
                            {{ Str::limit($memory->content, 80) }}
                        </td>
                        <td class="px-6 py-4 text-sm">
                            @php
                                $tierColors = [
                                    'working'   => 'bg-gray-100 text-gray-700',
                                    'proposed'  => 'bg-amber-100 text-amber-800',
                                    'canonical' => 'bg-green-100 text-green-800',
                                    'facts'     => 'bg-blue-100 text-blue-800',
                                    'decisions' => 'bg-purple-100 text-purple-800',
                                    'failures'  => 'bg-red-100 text-red-800',
                                    'successes' => 'bg-emerald-100 text-emerald-800',
                                ];
                                $tierValue = $memory->tier?->value ?? 'working';
                                $tierColor = $tierColors[$tierValue] ?? 'bg-gray-100 text-gray-700';
                            @endphp
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $tierColor }}">
                                {{ ucfirst($tierValue) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm">
                            @if($memory->belief_type)
                                @php
                                    $statusColors = [
                                        'active'      => 'bg-green-100 text-green-800',
                                        'inferred'    => 'bg-amber-100 text-amber-800',
                                        'exploratory' => 'bg-sky-100 text-sky-800',
                                        'superseded'  => 'bg-gray-200 text-gray-500 line-through',
                                    ];
                                    $statusValue = $memory->belief_status?->value ?? 'active';
                                @endphp
                                <div class="flex flex-col gap-1">
                                    <span class="inline-flex items-center rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-800">
                                        {{ Str::headline($memory->belief_type->value) }}@if($memory->preference_subtype) · {{ ucfirst($memory->preference_subtype->value) }}@endif
                                    </span>
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusColors[$statusValue] ?? 'bg-gray-100 text-gray-700' }}">
                                        {{ ucfirst($statusValue) }}
                                    </span>
                                </div>
                            @else
                                <span class="text-xs text-gray-300">-</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm">
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">
                                {{ $memory->source_type }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            {{ $memory->project?->title ?? '-' }}
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            {{ $memory->created_at->diffForHumans() }}
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-400">
                            <i class="fa-solid fa-chevron-right text-base transition {{ $expandedId === $memory->id ? 'rotate-90' : '' }}"></i>
                        </td>
                    </tr>

                    @if($expandedId === $memory->id)
                        <tr>
                            <td colspan="8" class="bg-gray-50 px-6 py-4">
                                <div class="space-y-3">
                                    {{-- Contradiction warning --}}
                                    @if($memory->conflict_flag)
                                        <div class="rounded-lg border border-red-200 bg-red-50 p-3">
                                            <h4 class="flex items-center gap-1 text-xs font-semibold uppercase text-red-700">
                                                <i class="fa-solid fa-triangle-exclamation"></i> Contradiction flagged
                                            </h4>
                                            <p class="mt-1 text-xs text-red-600">This belief was flagged as contradicting another. Review both and resolve.</p>
                                            @if($memory->conflictsWith)
                                                <div class="mt-2 rounded bg-white p-2 text-xs text-gray-700">
                                                    <span class="font-medium text-gray-500">Conflicts with:</span>
                                                    {{ Str::limit($memory->conflictsWith->content, 200) }}
                                                </div>
                                            @endif
                                            <div class="mt-2 flex flex-wrap gap-2" @click.stop>
                                                <button wire:click.stop="resolveConflict('{{ $memory->id }}', 'supersede')"
                                                    wire:confirm="Keep this memory and mark the conflicting one superseded?"
                                                    class="rounded bg-primary-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-primary-700">
                                                    Keep this — supersede the other
                                                </button>
                                                <button wire:click.stop="resolveConflict('{{ $memory->id }}', 'dismiss')"
                                                    wire:confirm="Dismiss this contradiction as a false positive?"
                                                    class="rounded border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-100">
                                                    Not a conflict — dismiss
                                                </button>
                                            </div>
                                        </div>
                                    @endif

                                    {{-- Full content --}}
                                    <div>
                                        <h4 class="text-xs font-medium uppercase text-gray-500">Content</h4>
                                        <div class="mt-1 rounded-lg bg-gray-900 p-4">
                                            <pre class="overflow-auto whitespace-pre-wrap text-xs text-green-400">{{ $memory->content }}</pre>
                                        </div>
                                    </div>

                                    {{-- Tier + proposed_by info --}}
                                    <div class="flex flex-wrap items-center gap-3">
                                        <span class="text-xs text-gray-500">Tier: <strong>{{ ucfirst($memory->tier?->value ?? 'working') }}</strong></span>
                                        @if($memory->proposed_by)
                                            <span class="text-xs text-gray-500">Proposed by: <strong>{{ $memory->proposed_by }}</strong></span>
                                        @endif
                                        @if($memory->belief_type)
                                            <span class="text-xs text-gray-500">Belief: <strong>{{ Str::headline($memory->belief_type->value) }}</strong></span>
                                        @endif
                                        @if($memory->belief_status)
                                            <span class="text-xs text-gray-500">Status: <strong>{{ ucfirst($memory->belief_status->value) }}</strong></span>
                                        @endif
                                        @if($memory->domain)
                                            <span class="text-xs text-gray-500">Domain: <strong>{{ $memory->domain }}</strong></span>
                                        @endif
                                    </div>

                                    {{-- Why it matters --}}
                                    @if($memory->why_it_matters)
                                        <div>
                                            <h4 class="text-xs font-medium uppercase text-gray-500">Why it matters</h4>
                                            <p class="mt-1 text-sm text-gray-700">{{ $memory->why_it_matters }}</p>
                                        </div>
                                    @endif

                                    {{-- Ruled-out alternatives --}}
                                    @if(!empty($memory->rejected_alternatives))
                                        <div>
                                            <h4 class="text-xs font-medium uppercase text-gray-500">Ruled-out alternatives</h4>
                                            <ul class="mt-1 space-y-1">
                                                @foreach($memory->rejected_alternatives as $alt)
                                                    <li class="text-sm text-gray-700">
                                                        <span class="font-medium text-red-600">&#10007; {{ $alt['option'] ?? '' }}</span>
                                                        @if(!empty($alt['reason']))
                                                            <span class="text-gray-500">&mdash; {{ $alt['reason'] }}</span>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif

                                    {{-- Decision lineage --}}
                                    @if($memory->supersedes_id || $memory->belief_status?->value === 'superseded')
                                        <div>
                                            <h4 class="text-xs font-medium uppercase text-gray-500">Decision lineage</h4>
                                            @if($memory->supersedes)
                                                <p class="mt-1 text-xs text-gray-600">
                                                    Supersedes: <span class="text-gray-800">{{ Str::limit($memory->supersedes->content, 160) }}</span>
                                                </p>
                                            @elseif($memory->supersedes_id)
                                                <p class="mt-1 text-xs text-gray-400">Supersedes a memory that is no longer available.</p>
                                            @endif
                                            @if($memory->belief_status?->value === 'superseded')
                                                <p class="mt-1 text-xs text-amber-600">This belief has been superseded and is no longer injected into agent context.</p>
                                            @endif
                                        </div>
                                    @endif

                                    {{-- Tags editor --}}
                                    <div x-data="{ editing: false, tagInput: '{{ implode(', ', $memory->tags ?? []) }}' }" class="flex flex-wrap items-center gap-2">
                                        <span class="text-xs font-medium uppercase text-gray-500">Tags:</span>
                                        @if(!empty($memory->tags))
                                            @foreach($memory->tags as $tag)
                                                <span class="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800">{{ $tag }}</span>
                                            @endforeach
                                        @else
                                            <span class="text-xs text-gray-400 italic">none</span>
                                        @endif

                                        <template x-if="!editing">
                                            <button @click.stop="editing = true" class="text-xs text-primary-600 hover:text-primary-800">Edit</button>
                                        </template>
                                        <template x-if="editing">
                                            <div class="flex items-center gap-2" @click.stop>
                                                <input x-model="tagInput" type="text" placeholder="barsy:client, barsy:shared"
                                                    class="rounded border border-gray-300 px-2 py-1 text-xs focus:border-primary-500 focus:ring-primary-500" />
                                                <button @click="$wire.updateTags('{{ $memory->id }}', tagInput); editing = false"
                                                    class="rounded bg-primary-600 px-2 py-1 text-xs text-white hover:bg-primary-700">Save</button>
                                                <button @click="editing = false" class="text-xs text-gray-500 hover:text-gray-700">Cancel</button>
                                            </div>
                                        </template>
                                    </div>

                                    {{-- Metadata --}}
                                    @if($memory->metadata && count($memory->metadata) > 0)
                                        <div>
                                            <h4 class="text-xs font-medium uppercase text-gray-500">Metadata</h4>
                                            <div class="mt-1 rounded-lg bg-gray-900 p-4">
                                                <pre class="overflow-auto text-xs text-green-400">{{ json_encode($memory->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                            </div>
                                        </div>
                                    @endif

                                    {{-- Actions --}}
                                    <div class="flex flex-wrap items-center gap-4 pt-2">
                                        <span class="text-xs text-gray-400">ID: {{ $memory->id }}</span>
                                        @if($memory->source_id)
                                            <span class="text-xs text-gray-400">Source ID: {{ $memory->source_id }}</span>
                                        @endif
                                        <span class="text-xs text-gray-400">{{ $memory->created_at->format('Y-m-d H:i:s') }}</span>

                                        {{-- Promote button for proposed memories --}}
                                        @if($memory->tier?->value === 'proposed')
                                            <button wire:click.stop="promoteTier('{{ $memory->id }}', 'canonical')"
                                                wire:confirm="Promote this memory to Canonical?"
                                                class="rounded bg-green-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-700">
                                                Promote to Canonical
                                            </button>
                                        @endif

                                        <button wire:click.stop="deleteMemory('{{ $memory->id }}')"
                                            wire:confirm="Are you sure you want to delete this memory?"
                                            class="ml-auto text-sm text-red-600 hover:text-red-800">
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="8" class="px-6 py-12 text-center text-sm text-gray-400">
                            No memories found. Agents will store memories as they execute tasks.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $memories->links() }}
    </div>
</div>
