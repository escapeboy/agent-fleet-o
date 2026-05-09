<div>
    @if(session('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('message') }}</div>
    @endif

    <div class="mb-6">
        <p class="text-sm text-gray-500">All pending work that needs your attention — approvals, outbound proposals, and human tasks.</p>
    </div>

    {{-- Filter pills --}}
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach([
            'all' => 'All',
            'approvals' => 'Approvals',
            'human_tasks' => 'Human tasks',
            'proposals' => 'Outbound',
        ] as $key => $label)
            <button wire:click="setFilter('{{ $key }}')"
                class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium transition
                    {{ $filter === $key ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50' }}"
                data-test="inbox-filter-{{ $key }}">
                {{ $label }}
                <span class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] {{ $filter === $key ? 'bg-primary-100 text-primary-700' : 'text-gray-600' }}">
                    {{ $counts[$key] ?? 0 }}
                </span>
            </button>
        @endforeach
    </div>

    <div class="rounded-xl border border-gray-200 bg-white" data-test="inbox-list">
        @forelse($items as $item)
            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 last:border-0"
                 data-test="inbox-item"
                 data-test-kind="{{ $item->kind }}">
                <div class="flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-{{ ['approval' => 'blue', 'human_task' => 'purple', 'proposal' => 'amber'][$item->kind] ?? 'gray' }}-100 px-2 py-0.5 text-[10px] font-medium uppercase text-{{ ['approval' => 'blue', 'human_task' => 'purple', 'proposal' => 'amber'][$item->kind] ?? 'gray' }}-700">
                            {{ str_replace('_', ' ', $item->kind) }}
                        </span>
                        <span class="text-sm font-medium text-gray-900">{{ $item->title }}</span>
                        @if($item->slaState !== 'none')
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-medium
                                {{ $item->slaState === 'red' ? 'bg-red-100 text-red-700' : ($item->slaState === 'warn' ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700') }}"
                                data-test="inbox-sla-{{ $item->slaState }}">
                                @if($item->slaState === 'red') SLA expired
                                @elseif($item->slaState === 'warn') SLA &lt; 1h
                                @else SLA OK
                                @endif
                            </span>
                        @endif
                    </div>
                    @if($item->subtitle)
                        <p class="mt-0.5 text-xs text-gray-500">{{ Str::limit($item->subtitle, 120) }}</p>
                    @endif
                    <p class="mt-1 text-[11px] text-gray-400">{{ $item->createdAt?->diffForHumans() }}</p>
                </div>
                <div class="flex items-center gap-2">
                    @if(in_array($item->kind, ['approval', 'human_task']))
                        <button wire:click="quickApprove('{{ $item->id }}')"
                            class="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-green-700"
                            data-test="inbox-approve">Approve</button>
                        <button wire:click="quickReject('{{ $item->id }}')"
                            wire:confirm="Reject this item?"
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"
                            data-test="inbox-reject">Reject</button>
                    @else
                        <a href="{{ $item->detailUrl }}" class="text-xs font-medium text-primary-600 hover:text-primary-800">Review →</a>
                    @endif
                </div>
            </div>
        @empty
            <div class="px-6 py-12 text-center text-sm text-gray-400" data-test="inbox-empty">
                Nothing pending. All clear.
            </div>
        @endforelse
    </div>
</div>
