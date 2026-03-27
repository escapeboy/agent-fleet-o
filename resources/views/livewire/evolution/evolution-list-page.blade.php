<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Evolution Proposals</h1>
            <p class="mt-1 text-sm text-gray-500">Review AI-generated proposals to improve your agents based on execution analysis.</p>
        </div>
    </div>

    @if($success)
        <div class="rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ $success }}</div>
    @endif
    @if($error)
        <div class="rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">{{ $error }}</div>
    @endif

    {{-- Status tabs + Agent filter --}}
    <div class="flex flex-wrap items-center gap-4">
        <div class="flex rounded-xl border border-gray-200 bg-white p-1 shadow-sm">
            @foreach([
                'pending'  => ['label' => 'Pending',  'color' => 'yellow'],
                'approved' => ['label' => 'Approved', 'color' => 'blue'],
                'applied'  => ['label' => 'Applied',  'color' => 'green'],
                'rejected' => ['label' => 'Rejected', 'color' => 'gray'],
            ] as $status => $meta)
                <button wire:click="setStatus('{{ $status }}')"
                    class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors
                        {{ $statusFilter === $status
                            ? 'bg-gray-900 text-white'
                            : 'text-gray-600 hover:bg-gray-50' }}">
                    {{ $meta['label'] }}
                    @if($counts[$status] > 0)
                        <span class="rounded-full px-1.5 py-0.5 text-xs font-semibold
                            {{ $statusFilter === $status ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-600' }}">
                            {{ $counts[$status] }}
                        </span>
                    @endif
                </button>
            @endforeach
        </div>

        @if($agents->isNotEmpty())
            <select wire:model.live="agentFilter"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 focus:border-primary-500 focus:ring-primary-500">
                <option value="">All agents</option>
                @foreach($agents as $agent)
                    <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                @endforeach
            </select>
        @endif
    </div>

    {{-- Proposals list --}}
    <div class="space-y-3">
        @forelse($proposals as $proposal)
            <div class="rounded-xl border bg-white shadow-sm
                {{ match($proposal->status->value) {
                    'pending'  => 'border-yellow-200',
                    'approved' => 'border-blue-200',
                    'applied'  => 'border-green-200',
                    'rejected' => 'border-gray-200',
                    default    => 'border-gray-200',
                } }} p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        {{-- Status badge --}}
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                            {{ match($proposal->status->value) {
                                'pending'  => 'bg-yellow-100 text-yellow-800',
                                'approved' => 'bg-blue-100 text-blue-800',
                                'applied'  => 'bg-green-100 text-green-800',
                                'rejected' => 'bg-gray-100 text-gray-700',
                                default    => 'bg-gray-100 text-gray-700',
                            } }}">
                            {{ ucfirst($proposal->status->value) }}
                        </span>
                        {{-- Agent name --}}
                        @if($proposal->agent)
                            <a href="{{ route('agents.show', $proposal->agent) }}"
                               class="text-sm font-medium text-gray-900 hover:text-primary-600">
                                {{ $proposal->agent->name }}
                            </a>
                        @endif
                        {{-- Confidence --}}
                        @if($proposal->confidence_score > 0)
                            <span class="text-xs text-gray-500">
                                Confidence: <strong>{{ number_format($proposal->confidence_score * 100) }}%</strong>
                            </span>
                        @endif
                    </div>
                    <span class="text-xs text-gray-400">{{ $proposal->created_at->diffForHumans() }}</span>
                </div>

                {{-- Analysis --}}
                @if($proposal->analysis)
                    <p class="mt-3 text-sm text-gray-700">{{ $proposal->analysis }}</p>
                @endif

                {{-- Proposed changes --}}
                @if(!empty($proposal->proposed_changes))
                    <details class="mt-3">
                        <summary class="cursor-pointer text-xs font-medium uppercase tracking-wide text-gray-500 hover:text-gray-700">
                            Proposed changes
                        </summary>
                        <div class="mt-2 rounded-lg bg-gray-900 p-3">
                            <pre class="overflow-auto whitespace-pre-wrap text-xs text-green-400">{{ json_encode($proposal->proposed_changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        </div>
                    </details>
                @endif

                {{-- Reasoning --}}
                @if($proposal->reasoning)
                    <p class="mt-2 text-xs text-gray-500"><strong>Reasoning:</strong> {{ $proposal->reasoning }}</p>
                @endif

                {{-- Reviewer info --}}
                @if($proposal->reviewer && $proposal->reviewed_at)
                    <p class="mt-2 text-xs text-gray-400">
                        Reviewed by {{ $proposal->reviewer->name }} · {{ $proposal->reviewed_at->diffForHumans() }}
                    </p>
                @endif

                {{-- Actions --}}
                @if($proposal->status->value === 'pending')
                    <div class="mt-4 flex items-center gap-2 border-t border-gray-100 pt-4">
                        <button wire:click="approve('{{ $proposal->id }}')"
                            wire:loading.attr="disabled"
                            class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                            Approve
                        </button>
                        <button wire:click="reject('{{ $proposal->id }}')"
                            wire:loading.attr="disabled"
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                            Reject
                        </button>
                    </div>
                @elseif($proposal->status->value === 'approved')
                    <div class="mt-4 border-t border-gray-100 pt-4">
                        <button wire:click="apply('{{ $proposal->id }}')"
                            wire:loading.attr="disabled"
                            class="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-green-700 disabled:opacity-50">
                            <span wire:loading.remove wire:target="apply('{{ $proposal->id }}')">Apply Changes</span>
                            <span wire:loading wire:target="apply('{{ $proposal->id }}')">Applying...</span>
                        </button>
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-gray-200 bg-white p-12 text-center">
                <svg class="mx-auto h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                </svg>
                <p class="mt-3 text-sm text-gray-500">No {{ $statusFilter }} evolution proposals.</p>
                <p class="mt-1 text-xs text-gray-400">Open an agent's Evolution tab to generate proposals from execution history.</p>
            </div>
        @endforelse
    </div>

    {{ $proposals->links() }}
</div>
