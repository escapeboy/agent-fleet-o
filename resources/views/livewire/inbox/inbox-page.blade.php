<div>
    @if(session('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('message') }}</div>
    @endif

    <div class="mb-6 flex items-start justify-between gap-3">
        <p class="text-sm text-gray-500">All pending work that needs your attention — approvals, outbound proposals, and human tasks.</p>
        <button wire:click="toggleTriageSort"
            class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium transition
                {{ $sortByTriage ? 'border-purple-500 bg-purple-50 text-purple-700' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50' }}"
            data-test="inbox-sort-triage"
            title="Heuristic ranking by SLA proximity, item type, age and risk score">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
            {{ $sortByTriage ? 'AI sort: on' : 'AI sort: off' }}
        </button>
    </div>

    {{-- Custom team queues --}}
    <div class="mb-3 flex flex-wrap items-center gap-2" data-test="inbox-queues-bar">
        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500">Queues:</span>
        <button wire:click="selectQueue(null)"
            class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-medium transition
                {{ $activeQueueId === null ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50' }}"
            data-test="inbox-queue-default">
            Default
        </button>
        @foreach($queues as $queue)
            <button wire:click="selectQueue('{{ $queue->id }}')"
                class="group inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-medium transition
                    {{ $activeQueueId === $queue->id ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50' }}"
                data-test="inbox-queue-{{ $queue->slug }}">
                {{ $queue->name }}
                <button wire:click.stop="deleteQueue('{{ $queue->id }}')"
                    wire:confirm="Delete queue '{{ $queue->name }}'?"
                    class="ml-1 hidden text-gray-400 hover:text-red-600 group-hover:inline">
                    <i class="fa-solid fa-xmark text-[10px]"></i>
                </button>
            </button>
        @endforeach
        @unless($showCreateQueue)
            <button wire:click="startCreateQueue"
                class="inline-flex items-center gap-1 rounded-full border border-dashed border-gray-300 px-2.5 py-1 text-xs text-gray-500 hover:border-primary-400 hover:text-primary-600"
                data-test="inbox-queue-add">
                <i class="fa-solid fa-plus text-[10px]"></i> New queue
            </button>
        @endunless
    </div>

    @if($showCreateQueue)
        <div class="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-3" data-test="inbox-queue-create-form">
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[180px]">
                    <label class="mb-1 block text-xs font-medium text-gray-700">Queue name</label>
                    <input wire:model="newQueueName"
                        class="w-full rounded-md border border-gray-300 px-2 py-1 text-xs focus:border-primary-500 focus:ring-primary-500"
                        placeholder="e.g. Code reviews only">
                    @error('newQueueName')<p class="mt-1 text-[10px] text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700">Kinds</label>
                    <div class="flex gap-1">
                        @foreach(['approval' => 'A', 'human_task' => 'H', 'proposal' => 'P'] as $kind => $abbr)
                            <label class="inline-flex items-center gap-1 rounded border border-gray-200 bg-white px-2 py-1 text-[10px]">
                                <input type="checkbox" wire:model="newQueueKinds" value="{{ $kind }}"
                                    class="h-3 w-3 rounded border-gray-300 text-primary-600">
                                {{ $abbr }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="flex gap-2">
                    <button wire:click="cancelCreateQueue"
                        class="rounded border border-gray-300 px-3 py-1 text-xs text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button wire:click="createQueue"
                        class="rounded bg-primary-600 px-3 py-1 text-xs font-medium text-white hover:bg-primary-700"
                        data-test="inbox-queue-save">Save</button>
                </div>
            </div>
        </div>
    @endif

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

    {{-- Bulk action bar (visible when items selected) --}}
    @if(count($selectedApprovalIds) > 0)
        <div class="mb-3 flex items-center justify-between rounded-lg border border-primary-200 bg-primary-50 px-4 py-2"
             data-test="inbox-bulk-bar">
            <span class="text-sm font-medium text-primary-700">
                {{ count($selectedApprovalIds) }} selected
            </span>
            <div class="flex gap-2">
                <button wire:click="bulkApprove"
                    wire:confirm="Approve {{ count($selectedApprovalIds) }} item(s)?"
                    class="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-green-700"
                    data-test="inbox-bulk-approve">Approve all</button>
                <button wire:click="bulkReject"
                    wire:confirm="Reject {{ count($selectedApprovalIds) }} item(s)?"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"
                    data-test="inbox-bulk-reject">Reject all</button>
            </div>
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 bg-white" data-test="inbox-list">
        @forelse($items as $item)
            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 last:border-0"
                 data-test="inbox-item"
                 data-test-kind="{{ $item->kind }}">
                {{-- Selection checkbox (only for approvable items) --}}
                @if(in_array($item->kind, ['approval', 'human_task']))
                    <input type="checkbox"
                        wire:click="toggleSelection('{{ $item->id }}')"
                        @checked(in_array($item->id, $selectedApprovalIds))
                        class="mr-3 h-4 w-4 rounded border-gray-300 text-primary-600"
                        data-test="inbox-checkbox-{{ $item->id }}">
                @else
                    <span class="mr-3 inline-block w-4"></span>
                @endif
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
                        @if($item->triageRec !== 'low_priority')
                            <span class="inline-flex items-center gap-1 rounded-full bg-{{ $item->triageColor }}-100 px-2 py-0.5 text-[10px] font-medium text-{{ $item->triageColor }}-700"
                                  data-test="inbox-triage-{{ $item->triageRec }}"
                                  title="Triage score: {{ number_format($item->triageScore, 2) }}">
                                <i class="fa-solid fa-wand-magic-sparkles text-[8px]"></i>{{ $item->triageLabel }}
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
