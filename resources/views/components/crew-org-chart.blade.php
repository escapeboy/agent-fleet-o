@props([
    'crew',
    'members' => collect(),
    'activeAgentIds' => [],
])

@php
    $coordinator = $crew->coordinator;
    $qa = $crew->qaAgent;
    $workers = $members->filter(fn ($m) => $m->agent_id !== ($coordinator?->id) && $m->agent_id !== ($qa?->id));
    $bottomCount = ($qa ? 1 : 0) + $workers->count();

    $tileBase = 'flex w-48 shrink-0 flex-col items-center rounded-xl border-2 px-4 py-3 text-center shadow-sm';
    $coordinatorTile = $tileBase.' border-blue-300 bg-blue-50';
    $qaTile = $tileBase.' border-purple-300 bg-purple-50';
    $workerTile = $tileBase.' border-gray-200 bg-white';
@endphp

<div class="rounded-xl border border-gray-200 bg-white p-6"
     data-test="crew-org-chart"
     data-test-bottom-count="{{ $bottomCount }}">
    <div class="mb-4 flex items-center justify-between">
        <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-500">Crew structure</h3>
        <span class="text-xs text-gray-400">{{ $crew->agentCount() }} {{ Str::plural('agent', $crew->agentCount()) }}</span>
    </div>

    {{-- Mobile fallback (<md): plain stacked list --}}
    <div class="space-y-3 md:hidden">
        @if($coordinator)
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-3">
                <div class="text-xs font-semibold uppercase text-blue-700">Coordinator</div>
                <a href="{{ route('agents.show', $coordinator) }}"
                   class="font-medium text-primary-700 hover:text-primary-800">{{ $coordinator->name }}</a>
                <div class="text-xs text-gray-500">{{ $coordinator->role ?? 'No role' }}</div>
            </div>
        @endif
        @if($qa)
            <div class="rounded-lg border border-purple-200 bg-purple-50 p-3">
                <div class="text-xs font-semibold uppercase text-purple-700">QA</div>
                <a href="{{ route('agents.show', $qa) }}"
                   class="font-medium text-primary-700 hover:text-primary-800">{{ $qa->name }}</a>
                <div class="text-xs text-gray-500">{{ $qa->role ?? 'No role' }}</div>
            </div>
        @endif
        @foreach($workers as $worker)
            <div class="rounded-lg border border-gray-200 bg-white p-3">
                <div class="flex items-center justify-between">
                    <div class="text-xs font-semibold uppercase text-gray-500">Worker {{ $worker->sort_order + 1 }}</div>
                    @if($worker->isExternal())
                        <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-700">external</span>
                    @endif
                </div>
                @if($worker->agent)
                    <a href="{{ route('agents.show', $worker->agent) }}"
                       class="font-medium text-primary-700 hover:text-primary-800">{{ $worker->agent->name }}</a>
                    <div class="text-xs text-gray-500">{{ $worker->agent->role ?? 'No role' }}</div>
                @else
                    <div class="text-sm text-gray-400">External agent</div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- ≥md: org-chart layout --}}
    <div class="hidden md:flex md:flex-col md:items-center">
        {{-- Coordinator tile --}}
        @if($coordinator)
            <div class="{{ $coordinatorTile }} {{ in_array($coordinator->id, $activeAgentIds) ? 'ring-2 ring-blue-400 ring-offset-2' : '' }}"
                 data-test="org-chart-coordinator">
                <div class="mb-1 flex h-8 w-8 items-center justify-center rounded-full bg-blue-100 text-blue-600">
                    <i class="fa-solid fa-shield text-sm"></i>
                </div>
                <div class="text-[10px] font-semibold uppercase tracking-wider text-blue-700">Coordinator</div>
                <a href="{{ route('agents.show', $coordinator) }}"
                   class="mt-0.5 max-w-full truncate text-sm font-medium text-primary-700 hover:text-primary-800">
                    {{ $coordinator->name }}
                </a>
                <div class="max-w-full truncate text-[11px] text-gray-500">{{ $coordinator->role ?? 'No role' }}</div>
                <div class="mt-1 max-w-full truncate text-[10px] text-gray-400">{{ $coordinator->provider }}/{{ $coordinator->model }}</div>
            </div>
        @else
            <div class="{{ $tileBase }} border-dashed border-gray-300 text-gray-400" data-test="org-chart-coordinator-missing">
                <div class="text-[10px] font-semibold uppercase tracking-wider">Coordinator</div>
                <div class="text-sm">—</div>
            </div>
        @endif

        {{-- Vertical trunk --}}
        @if($bottomCount > 0)
            <div class="h-6 w-px bg-gray-300"></div>

            {{-- Bottom row: QA + workers --}}
            <div class="flex flex-row flex-wrap items-start justify-center gap-x-6 gap-y-4">
                @if($qa)
                    <div class="flex flex-col items-center">
                        <div class="h-4 w-px bg-gray-300"></div>
                        <div class="{{ $qaTile }} {{ in_array($qa->id, $activeAgentIds) ? 'ring-2 ring-purple-400 ring-offset-2' : '' }}"
                             data-test="org-chart-qa">
                            <div class="mb-1 flex h-8 w-8 items-center justify-center rounded-full bg-purple-100 text-purple-600">
                                <i class="fa-solid fa-circle-check text-sm"></i>
                            </div>
                            <div class="text-[10px] font-semibold uppercase tracking-wider text-purple-700">QA</div>
                            <a href="{{ route('agents.show', $qa) }}"
                               class="mt-0.5 max-w-full truncate text-sm font-medium text-primary-700 hover:text-primary-800">
                                {{ $qa->name }}
                            </a>
                            <div class="max-w-full truncate text-[11px] text-gray-500">{{ $qa->role ?? 'No role' }}</div>
                            <div class="mt-1 max-w-full truncate text-[10px] text-gray-400">{{ $qa->provider }}/{{ $qa->model }}</div>
                        </div>
                    </div>
                @endif

                @foreach($workers as $worker)
                    <div class="flex flex-col items-center">
                        <div class="h-4 w-px bg-gray-300"></div>
                        <div class="{{ $workerTile }} {{ in_array($worker->agent_id, $activeAgentIds) ? 'ring-2 ring-gray-400 ring-offset-2' : '' }}"
                             data-test="org-chart-worker">
                            <div class="mb-1 flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-gray-600">
                                <i class="fa-solid fa-user-gear text-sm"></i>
                            </div>
                            <div class="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider text-gray-600">
                                <span>Worker {{ $worker->sort_order + 1 }}</span>
                                @if($worker->isExternal())
                                    <span class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[9px] font-semibold text-amber-700">external</span>
                                @endif
                            </div>
                            @if($worker->agent)
                                <a href="{{ route('agents.show', $worker->agent) }}"
                                   class="mt-0.5 max-w-full truncate text-sm font-medium text-primary-700 hover:text-primary-800">
                                    {{ $worker->agent->name }}
                                </a>
                                <div class="max-w-full truncate text-[11px] text-gray-500">{{ $worker->agent->role ?? 'No role' }}</div>
                                <div class="mt-1 max-w-full truncate text-[10px] text-gray-400">{{ $worker->agent->provider }}/{{ $worker->agent->model }}</div>
                            @else
                                <div class="mt-0.5 text-sm text-gray-400">External</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Empty workers/qa hint --}}
        @if($bottomCount === 0)
            <p class="mt-4 text-xs italic text-gray-400">Coordinator-only crew — no QA or workers yet.</p>
        @endif
    </div>
</div>
