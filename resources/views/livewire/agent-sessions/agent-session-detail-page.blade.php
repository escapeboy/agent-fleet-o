<div>
    @php
        $session = $this->session;
        $statusClasses = [
            'pending' => 'bg-gray-100 text-gray-700',
            'active' => 'bg-green-100 text-green-700',
            'sleeping' => 'bg-blue-100 text-blue-700',
            'completed' => 'bg-gray-100 text-gray-700',
            'cancelled' => 'bg-amber-100 text-amber-700',
            'failed' => 'bg-red-100 text-red-700',
        ];
        $isTerminal = $session->status?->isTerminal() ?? false;
    @endphp

    @if (session('message'))
        <div class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('message') }}
        </div>
    @endif

    {{-- Header / state + actions --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4 rounded-lg border border-gray-200 bg-white p-5">
        <div>
            <div class="flex items-center gap-3">
                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusClasses[$session->status?->value] ?? 'bg-gray-100 text-gray-700' }}">
                    {{ $session->status?->label() ?? '—' }}
                </span>
                <span class="text-sm text-gray-500">{{ $session->agent?->name ?? 'No agent' }}</span>
            </div>
            <p class="mt-2 font-mono text-xs text-gray-400">{{ $session->id }}</p>
            <p class="mt-1 text-xs text-gray-500">
                Started {{ $session->started_at?->diffForHumans() ?? '—' }}
                &middot; Last heartbeat {{ $session->last_heartbeat_at?->diffForHumans() ?? '—' }}
                @if($session->last_known_sandbox_id)
                    &middot; Sandbox <span class="font-mono">{{ $session->last_known_sandbox_id }}</span>
                @endif
            </p>
        </div>

        <div class="flex items-center gap-2">
            {{-- Fork stays available on terminal sessions: branching a finished
                 run is the most common reason to fork. --}}
            @if($replay->totalEvents > 0)
                <button wire:click="openForkModal"
                    class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Fork
                </button>
            @endif
            @unless($isTerminal)
                <button wire:click="wake"
                    class="rounded-lg border border-primary-300 bg-primary-50 px-4 py-2 text-sm font-medium text-primary-700 hover:bg-primary-100">
                    Wake
                </button>
                <button wire:click="$set('showCancelConfirm', true)"
                    class="rounded-lg border border-red-300 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100">
                    Cancel
                </button>
            @else
                <span class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-medium text-gray-400">
                    Session is {{ $session->status?->label() }}
                </span>
            @endunless
        </div>
    </div>

    {{-- Cancel confirm --}}
    @if($showCancelConfirm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="$set('showCancelConfirm', false)">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h3 class="mb-2 text-lg font-semibold text-gray-900">Cancel session?</h3>
                <p class="mb-5 text-sm text-gray-600">This marks the session terminal. It cannot be woken again.</p>
                <div class="flex justify-end gap-2">
                    <button wire:click="$set('showCancelConfirm', false)"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Keep
                    </button>
                    <button wire:click="cancel"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                        Cancel Session
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Fork --}}
    @if($showForkModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="$set('showForkModal', false)">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h3 class="mb-2 text-lg font-semibold text-gray-900">Fork this session</h3>
                <p class="mb-4 text-sm text-gray-600">
                    Creates a new session under the same agent holding a copy of events 1&ndash;N.
                    This session keeps running and is not changed.
                </p>
                <x-form-input type="number" wire:model="forkAtSeq" label="Branch at event seq"
                    :hint="'1 to ' . $replay->totalEvents" :error="$errors->first('forkAtSeq')"
                    min="1" :max="$replay->totalEvents" />
                <div class="mt-5 flex justify-end gap-2">
                    <button wire:click="$set('showForkModal', false)"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button wire:click="fork"
                        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Fork Session
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Lineage --}}
    @if($parentSession || $forks->isNotEmpty())
        <div class="mb-6 rounded-lg border border-gray-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-semibold text-gray-900">Lineage</h2>
            @if($parentSession)
                <p class="text-sm text-gray-600">
                    Forked from
                    <a href="{{ route('agent-sessions.show', $parentSession->id) }}" wire:navigate
                        class="font-mono text-xs text-primary-600 hover:underline">{{ $parentSession->id }}</a>
                    at seq {{ $session->forked_at_seq }}.
                </p>
            @elseif($session->forked_at_seq)
                <p class="text-sm text-gray-600">
                    Forked at seq {{ $session->forked_at_seq }}. The parent session has since been pruned.
                </p>
            @endif
            @if($forks->isNotEmpty())
                <p class="mt-3 text-xs uppercase tracking-wider text-gray-500">Forks of this session</p>
                <ul class="mt-2 space-y-1">
                    @foreach($forks as $fork)
                        <li class="text-sm">
                            <a href="{{ route('agent-sessions.show', $fork->id) }}" wire:navigate
                                class="font-mono text-xs text-primary-600 hover:underline">{{ $fork->id }}</a>
                            <span class="text-gray-500">&middot; at seq {{ $fork->forked_at_seq }} &middot; {{ $fork->status?->label() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- Replay stats --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wider text-gray-500">Total Events</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900">{{ $replay->totalEvents }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wider text-gray-500">Tool Calls</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900">{{ $replay->toolCallCount }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wider text-gray-500">LLM Tokens</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format($replay->llmTotalTokens) }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wider text-gray-500">Handoffs</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900">{{ $replay->handoffCount }}</p>
        </div>
    </div>

    {{-- Append-only event timeline --}}
    <div class="rounded-lg border border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-5 py-3">
            <h2 class="text-sm font-semibold text-gray-900">Event Timeline</h2>
            <p class="text-xs text-gray-500">Newest first &middot; showing up to 500 events</p>
        </div>
        <ul class="divide-y divide-gray-100">
            @forelse($events as $event)
                <li class="flex items-start gap-4 px-5 py-3">
                    <span class="mt-0.5 w-10 shrink-0 font-mono text-xs text-gray-400">#{{ $event->seq }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                                {{ $event->kind?->value ?? 'unknown' }}
                            </span>
                            <span class="text-xs text-gray-400">{{ $event->created_at?->diffForHumans() }}</span>
                        </div>
                        @if(!empty($event->payload))
                            <pre class="mt-1 overflow-x-auto whitespace-pre-wrap break-words rounded bg-gray-50 p-2 text-xs text-gray-600">{{ json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        @endif
                    </div>
                </li>
            @empty
                <li class="px-5 py-10 text-center text-sm text-gray-500">No events recorded.</li>
            @endforelse
        </ul>
    </div>
</div>
