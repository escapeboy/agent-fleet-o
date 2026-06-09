<div class="space-y-6">
    @if (session('message'))
        <div class="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
            {{ session('message') }}
        </div>
    @endif
    @if (session('error'))
        <div class="rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
            {{ session('error') }}
        </div>
    @endif

    <div class="flex items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Proposal Review Queue</h2>
            <p class="text-sm text-gray-500">
                Agent-extracted memories awaiting human review. Approve to promote into a curated tier, or reject with a reason.
            </p>
        </div>
        <a href="{{ route('memory.index') }}" class="text-sm text-primary-600 hover:text-primary-700 whitespace-nowrap">
            Browse all memories &rarr;
        </a>
    </div>

    <div class="max-w-sm">
        <x-form-input
            wire:model.live.debounce.300ms="search"
            name="search"
            placeholder="Search proposal content..."
            compact
        />
    </div>

    <div class="space-y-3">
        @forelse ($proposals as $memory)
            <div class="rounded-lg border border-gray-200 bg-white shadow-sm" wire:key="proposal-{{ $memory->id }}">
                <div class="p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-gray-900 {{ $expandedId === $memory->id ? '' : 'line-clamp-3' }}">
                                {{ $memory->content }}
                            </p>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                                <span>Proposed by <span class="font-medium text-gray-700">{{ $memory->proposed_by ?? 'unknown' }}</span></span>
                                @if ($memory->agent)
                                    <span>Agent: {{ $memory->agent->name }}</span>
                                @endif
                                @if ($memory->project)
                                    <span>Project: {{ $memory->project->title }}</span>
                                @endif
                                <span>Confidence: {{ number_format((float) ($memory->confidence ?? 0), 2) }}</span>
                                <span>{{ $memory->created_at?->diffForHumans() }}</span>
                            </div>
                            @if (! empty($memory->tags))
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($memory->tags as $tag)
                                        <span class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $tag }}</span>
                                    @endforeach
                                </div>
                            @endif
                            <button
                                type="button"
                                wire:click="toggleExpand('{{ $memory->id }}')"
                                class="mt-2 text-xs text-primary-600 hover:text-primary-700"
                            >
                                {{ $expandedId === $memory->id ? 'Show less' : 'Show more' }}
                            </button>
                        </div>
                    </div>

                    @if ($rejectingId === $memory->id)
                        <div class="mt-4 space-y-2 rounded-md bg-red-50 border border-red-200 p-3">
                            <x-form-textarea
                                wire:model="rejectReason"
                                name="rejectReason"
                                label="Rejection reason"
                                placeholder="Why is this proposal being rejected?"
                                rows="2"
                            />
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    wire:click="reject('{{ $memory->id }}')"
                                    class="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700"
                                >
                                    Confirm reject
                                </button>
                                <button
                                    type="button"
                                    wire:click="cancelReject"
                                    class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    @else
                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <div class="flex items-center gap-2">
                                <select
                                    wire:model="approveTier.{{ $memory->id }}"
                                    class="rounded-md border border-gray-300 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500"
                                >
                                    @foreach ($curatedTiers as $tier)
                                        <option value="{{ $tier->value }}">{{ ucfirst($tier->value) }}</option>
                                    @endforeach
                                </select>
                                <button
                                    type="button"
                                    wire:click="approve('{{ $memory->id }}')"
                                    class="rounded-md bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700"
                                >
                                    Approve
                                </button>
                            </div>
                            <button
                                type="button"
                                wire:click="startReject('{{ $memory->id }}')"
                                class="rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50"
                            >
                                Reject
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center">
                <p class="text-sm font-medium text-gray-900">No proposals to review</p>
                <p class="mt-1 text-sm text-gray-500">Agent-proposed memories awaiting review will appear here.</p>
            </div>
        @endforelse
    </div>

    <div>
        {{ $proposals->links() }}
    </div>
</div>
