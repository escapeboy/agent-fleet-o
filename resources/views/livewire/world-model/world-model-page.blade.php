<div class="container mx-auto max-w-4xl space-y-6 px-4 py-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">World Model</h1>
            <p class="mt-1 text-sm text-gray-500">
                A concise briefing auto-generated from the last 14 days of team activity — signals, experiments, memories.
                Injected into every agent's system prompt so each LLM call has team-wide context.
            </p>
        </div>
        @if($canManage)
            <button wire:click="rebuild"
                    wire:loading.attr="disabled"
                    wire:target="rebuild"
                    class="shrink-0 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-primary-700 disabled:opacity-60">
                <span wire:loading.remove wire:target="rebuild">Rebuild now</span>
                <span wire:loading wire:target="rebuild">Queuing…</span>
            </button>
        @endif
    </div>

    @if(session('message'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('message') }}
        </div>
    @endif

    @if($model === null)
        <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-8 text-center">
            <div class="text-5xl text-gray-300">⊙</div>
            <h2 class="mt-4 text-lg font-medium text-gray-900">No digest yet</h2>
            <p class="mt-1 text-sm text-gray-500">
                The nightly schedule hasn't run for this team yet. Once you have a few signals or experiments,
                a digest will be built automatically — or trigger one manually with "Rebuild now".
            </p>
        </div>
    @else
        <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-6 py-3">
                <div class="flex items-center gap-2 text-xs text-gray-500">
                    <span>Generated {{ $model->generated_at?->diffForHumans() ?? 'never' }}</span>
                    @if($isStale)
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 font-medium text-amber-700">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                            Stale (&gt; 7 days old)
                        </span>
                    @endif
                </div>
                @if($model->provider || $model->model)
                    <span class="font-mono text-[10px] text-gray-400">{{ $model->provider }}/{{ $model->model }}</span>
                @endif
            </div>

            <div class="px-6 py-5">
                @if(! empty($model->digest))
                    <div class="prose prose-sm max-w-none text-gray-800">
                        {!! \Illuminate\Support\Str::markdown($model->digest) !!}
                    </div>
                @else
                    <p class="text-sm italic text-gray-500">
                        No digest generated. This usually means there was no team activity in the window.
                    </p>
                @endif
            </div>

            @if(! empty($model->stats))
                <div class="border-t border-gray-100 px-6 py-3">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
                        <span class="font-medium text-gray-600">Built from:</span>
                        @if(isset($model->stats['signal_count']))
                            <span>{{ $model->stats['signal_count'] }} signals</span>
                        @endif
                        @if(isset($model->stats['experiment_count']))
                            <span>{{ $model->stats['experiment_count'] }} experiments</span>
                        @endif
                        @if(isset($model->stats['memory_count']))
                            <span>{{ $model->stats['memory_count'] }} memories</span>
                        @endif
                        @if(isset($model->stats['window_days']))
                            <span class="text-gray-400">· last {{ $model->stats['window_days'] }} days</span>
                        @endif
                        @if(isset($model->stats['skipped']))
                            <span class="italic text-amber-600">· {{ $model->stats['skipped'] }}</span>
                        @endif
                        @if(isset($model->stats['error']))
                            <span class="italic text-red-600">· error: {{ $model->stats['error'] }}</span>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <div class="rounded-lg border border-gray-100 bg-gray-50 px-6 py-4 text-xs text-gray-500">
            <p>
                <strong class="text-gray-700">How this is used:</strong> every time an agent runs (experiment stage,
                crew member, direct execution), this digest is appended to the system prompt after memory + knowledge-graph
                context. Keeps LLM calls concise — agents don't need to re-fetch team background every turn.
            </p>
            <p class="mt-2">
                <strong class="text-gray-700">When it rebuilds:</strong> automatically at 02:15 UTC daily, and on demand
                via the "Rebuild now" button above. Rebuilds skip the LLM call entirely if the 14-day window is empty —
                no spend for inactive teams.
            </p>
        </div>
    @endif
</div>
