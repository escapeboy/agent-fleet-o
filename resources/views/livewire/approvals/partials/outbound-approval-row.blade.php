@php
    $outbound = $approval->outboundProposal;
    $channel = $outbound?->channel?->value ?? 'unknown';
    $isPending = $approval->status === \App\Domain\Approval\Enums\ApprovalStatus::Pending;
    $statusColor = match (true) {
        $approval->status === \App\Domain\Approval\Enums\ApprovalStatus::Pending => 'amber',
        $approval->status === \App\Domain\Approval\Enums\ApprovalStatus::Approved => 'emerald',
        $approval->status === \App\Domain\Approval\Enums\ApprovalStatus::Rejected => 'red',
        $approval->status === \App\Domain\Approval\Enums\ApprovalStatus::Expired => 'gray',
        default => 'gray',
    };
    $contentPreview = is_array($outbound?->content) ? ($outbound->content['subject'] ?? $outbound->content['body'] ?? json_encode($outbound->content)) : '—';
    $targetPreview = is_array($outbound?->target) ? ($outbound->target['email'] ?? $outbound->target['phone'] ?? $outbound->target['handle'] ?? json_encode($outbound->target)) : '—';
@endphp
<div class="rounded-xl border border-gray-200 bg-white">
    <div class="flex items-center gap-3 px-4 py-3">
        <i class="fa-solid fa-paper-plane text-xs text-gray-400"></i>

        <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-{{ $statusColor }}-50 px-2 py-0.5 text-xs font-medium text-{{ $statusColor }}-700 ring-1 ring-inset ring-{{ $statusColor }}-600/20">
            <i class="fa-solid fa-envelope"></i>
            {{ ucfirst($approval->status->value) }}
        </span>

        <span class="inline-flex items-center rounded-md bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800">
            outbound · {{ $channel }}
        </span>

        <div class="flex-1 min-w-0">
            <p class="truncate text-sm font-medium text-gray-900">
                {{ \Illuminate\Support\Str::limit((string) $contentPreview, 80) }}
            </p>
            <p class="truncate text-xs text-gray-500">
                <i class="fa-solid fa-arrow-right text-[10px] text-gray-400"></i>
                {{ \Illuminate\Support\Str::limit((string) $targetPreview, 60) }}
            </p>
        </div>

        <span class="shrink-0 font-mono text-[10px] text-gray-400">
            {{ $approval->created_at?->diffForHumans() ?? '—' }}
        </span>
    </div>

    @if($isPending)
        <div class="flex gap-2 border-t border-gray-100 px-4 py-2">
            <button wire:click="approve('{{ $approval->id }}')"
                class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">
                <i class="fa-solid fa-check mr-1"></i>
                Approve & Send
            </button>
            <button wire:click="openRejectModal('{{ $approval->id }}')"
                class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">
                <i class="fa-solid fa-xmark mr-1"></i>
                Reject
            </button>
        </div>
    @endif
</div>
