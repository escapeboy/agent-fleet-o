<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-900">Self-Evolution Proposals</h3>
        <button wire:click="analyze"
            wire:loading.attr="disabled"
            class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700 disabled:opacity-50">
            <span wire:loading.remove wire:target="analyze">Analyze & Propose</span>
            <span wire:loading wire:target="analyze" class="flex items-center gap-1">
                <svg class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Analyzing...
            </span>
        </button>
    </div>

    @if($success)
        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ $success }}</div>
    @endif
    @if($error)
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $error }}</div>
    @endif

    @forelse($proposals as $proposal)
        <div class="rounded-lg border {{ $proposal->status->value === 'pending' ? 'border-yellow-200 bg-yellow-50' : ($proposal->status->value === 'applied' ? 'border-green-200 bg-green-50' : ($proposal->status->value === 'approved' ? 'border-blue-200 bg-blue-50' : 'border-gray-200 bg-gray-50')) }} p-4">
            <div class="mb-2 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                        {{ match($proposal->status->value) {
                            'pending' => 'bg-yellow-100 text-yellow-800',
                            'approved' => 'bg-blue-100 text-blue-800',
                            'applied' => 'bg-green-100 text-green-800',
                            'rejected' => 'bg-gray-100 text-gray-800',
                            default => 'bg-gray-100 text-gray-800',
                        } }}">
                        {{ ucfirst($proposal->status->value) }}
                    </span>
                    <span class="text-xs text-gray-400">{{ $proposal->created_at->diffForHumans() }}</span>
                    @if($proposal->confidence_score > 0)
                        <span class="text-xs text-gray-500">Confidence: {{ number_format($proposal->confidence_score * 100) }}%</span>
                    @endif
                </div>
            </div>

            <p class="mb-2 text-sm text-gray-700">{{ $proposal->analysis }}</p>

            @if(!empty($proposal->proposed_changes))
                <div class="mb-2">
                    <h4 class="text-xs font-medium uppercase text-gray-500">Proposed Changes</h4>
                    <div class="mt-1 rounded-lg bg-gray-900 p-3">
                        <pre class="overflow-auto whitespace-pre-wrap text-xs text-green-400">{{ json_encode($proposal->proposed_changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                </div>
            @endif

            @if($proposal->reasoning)
                <p class="mb-2 text-xs text-gray-500"><strong>Reasoning:</strong> {{ $proposal->reasoning }}</p>
            @endif

            @if($proposal->status->value === 'pending')
                <div class="flex items-center gap-2 pt-2">
                    <button wire:click="approve('{{ $proposal->id }}')"
                        class="rounded-lg bg-blue-600 px-3 py-1 text-xs font-medium text-white hover:bg-blue-700">
                        Approve
                    </button>
                    <button wire:click="reject('{{ $proposal->id }}')"
                        class="rounded-lg border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                        Reject
                    </button>
                </div>
            @elseif($proposal->status->value === 'approved')
                <div class="pt-2">
                    <button wire:click="apply('{{ $proposal->id }}')"
                        class="rounded-lg bg-green-600 px-3 py-1 text-xs font-medium text-white hover:bg-green-700">
                        Apply Changes
                    </button>
                </div>
            @endif
        </div>
    @empty
        <div class="rounded-lg border border-dashed border-gray-200 p-6 text-center">
            <p class="text-sm text-gray-400">No evolution proposals yet. Click "Analyze & Propose" to generate suggestions for improving this agent.</p>
        </div>
    @endforelse
</div>
