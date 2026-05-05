@php
    $status = $p->status instanceof \App\Domain\Approval\Enums\ActionProposalStatus
        ? $p->status
        : \App\Domain\Approval\Enums\ActionProposalStatus::tryFrom((string) $p->status);
    $color = $status?->color() ?? 'gray';
@endphp
<div class="rounded-xl border border-gray-200 bg-white">
    {{-- Header row --}}
    <button wire:click="toggleProposal('{{ $p->id }}')"
        class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-gray-50">
        <i class="fa-solid {{ $expandedProposalId === $p->id ? 'fa-chevron-down' : 'fa-chevron-right' }} text-xs text-gray-400"></i>

        <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-{{ $color }}-50 px-2 py-0.5 text-xs font-medium text-{{ $color }}-700 ring-1 ring-inset ring-{{ $color }}-600/20">
            <i class="fa-solid fa-bolt"></i>
            {{ $status?->label() ?? ucfirst((string) $p->status) }}
        </span>

        <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
            {{ $p->target_type }}
        </span>

        <span class="flex-1 truncate text-sm font-medium text-gray-900">{{ $p->summary }}</span>

        <span class="shrink-0 font-mono text-[10px] text-gray-400">
            {{ $p->created_at?->diffForHumans() ?? '—' }}
        </span>
    </button>

    {{-- Expanded drawer --}}
    @if($expandedProposalId === $p->id)
        <div class="space-y-4 border-t border-gray-100 px-4 py-4">
            {{-- Meta --}}
            <div class="grid grid-cols-2 gap-3 text-xs">
                <div>
                    <span class="text-gray-500">Risk:</span>
                    <span class="ml-1 font-medium text-gray-900">{{ $p->risk_level }}</span>
                </div>
                <div>
                    <span class="text-gray-500">Actor:</span>
                    <span class="ml-1 font-medium text-gray-900">
                        {{ $p->actorUser?->name ?? $p->actorAgent?->name ?? 'system' }}
                    </span>
                </div>
                @if($p->expires_at)
                    <div>
                        <span class="text-gray-500">Expires:</span>
                        <span class="ml-1 font-medium text-gray-900">{{ $p->expires_at->diffForHumans() }}</span>
                    </div>
                @endif
                @if($p->decided_at)
                    <div>
                        <span class="text-gray-500">Decided:</span>
                        <span class="ml-1 font-medium text-gray-900">{{ $p->decided_at->diffForHumans() }}</span>
                    </div>
                @endif
            </div>

            {{-- Payload --}}
            @if(! empty($p->payload))
                <div>
                    <p class="mb-1 text-xs font-semibold text-gray-700">Proposed action</p>
                    <pre class="max-h-48 overflow-auto rounded-md bg-gray-900 p-3 text-[11px] leading-tight text-gray-100">{{ json_encode($p->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            @endif

            {{-- Lineage chain --}}
            @if(! empty($p->lineage))
                <div>
                    <p class="mb-1 text-xs font-semibold text-gray-700">
                        <i class="fa-solid fa-link mr-1 text-gray-400"></i>
                        Lineage chain
                    </p>
                    <ol class="space-y-1.5">
                        @foreach($p->lineage as $step)
                            <li class="flex gap-2 rounded-md border border-gray-100 bg-gray-50 p-2 text-xs">
                                <span class="shrink-0 rounded bg-white px-1.5 py-0.5 font-mono text-[10px] uppercase text-gray-500 ring-1 ring-gray-200">
                                    {{ $step['role'] ?? $step['kind'] ?? 'step' }}
                                </span>
                                <span class="flex-1 text-gray-600">{{ $step['snippet'] ?? '' }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif

            {{-- Decision (if any) --}}
            @if($p->decision_reason)
                <div class="rounded-md border border-gray-200 bg-gray-50 p-2 text-xs">
                    <span class="font-semibold text-gray-700">Reason:</span>
                    <span class="ml-1 text-gray-600">{{ $p->decision_reason }}</span>
                </div>
            @endif

            {{-- Execution result / error --}}
            @if($p->executed_at)
                <div>
                    <p class="mb-1 text-xs font-semibold text-gray-700">
                        <i class="fa-solid fa-{{ $p->execution_error ? 'triangle-exclamation text-rose-500' : 'circle-check text-emerald-500' }} mr-1"></i>
                        Execution
                        <span class="ml-1 font-normal text-gray-400">{{ $p->executed_at->diffForHumans() }}</span>
                    </p>
                    @if($p->execution_error)
                        <pre class="max-h-32 overflow-auto rounded-md bg-rose-50 p-2 text-[11px] text-rose-700">{{ $p->execution_error }}</pre>
                    @elseif($p->execution_result)
                        <pre class="max-h-48 overflow-auto rounded-md bg-emerald-50 p-2 text-[11px] text-emerald-900">{{ json_encode($p->execution_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    @endif
                </div>
            @endif

            {{-- Actions --}}
            @if($status === \App\Domain\Approval\Enums\ActionProposalStatus::Pending)
                <div class="flex gap-2 border-t border-gray-100 pt-3">
                    <button wire:click="approveProposal('{{ $p->id }}')"
                        class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">
                        <i class="fa-solid fa-check mr-1"></i>
                        Approve
                    </button>
                    <button wire:click="openProposalReject('{{ $p->id }}')"
                        class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">
                        <i class="fa-solid fa-xmark mr-1"></i>
                        Reject
                    </button>
                </div>
            @endif
        </div>
    @endif
</div>
