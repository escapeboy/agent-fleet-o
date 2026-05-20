<div class="space-y-6">

    {{-- Status flash --}}
    @if(session('status'))
        <div class="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('status') }}
        </div>
    @endif

    {{-- Mode card --}}
    <div class="rounded-xl border border-gray-200 bg-white p-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="space-y-1">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-semibold text-gray-900">Operating mode</h3>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold
                        {{ $mode === 'phase1' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800' }}">
                        {{ $mode }}
                    </span>
                </div>
                <p class="text-sm text-gray-600">
                    @if($mode === 'phase1')
                        Phase 1 — autonomous investigation. The watchdog will open PRs against <code class="text-xs">develop</code> for actionable issues. Every PR is still T4-mode (human-merged).
                    @else
                        Phase 0 — read-only. The watchdog triages issues and sends digests only; no PRs are opened and Sentry issues are not mutated.
                    @endif
                </p>
                <p class="text-xs text-gray-500">
                    Configure via <code class="text-xs">SENTRY_WATCHDOG_MODE</code> in <code class="text-xs">.env</code>.
                </p>
            </div>
            <div>
                <button type="button" wire:click="runNow" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 rounded-md bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 disabled:opacity-60">
                    <svg wire:loading wire:target="runNow" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                        <path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" class="opacity-75"></path>
                    </svg>
                    Run now
                </button>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-4 border-t border-gray-100 pt-4 sm:grid-cols-3">
            <div>
                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Runs (30d)</div>
                <div class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($totalRuns30d) }}</div>
            </div>
            <div>
                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Critical issues (30d)</div>
                <div class="mt-1 text-2xl font-bold {{ $totalCritical30d > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($totalCritical30d) }}</div>
            </div>
            <div>
                <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Last run</div>
                <div class="mt-1 text-sm font-semibold text-gray-900">
                    {{ $lastRunAt ? $lastRunAt->diffForHumans() : '—' }}
                </div>
                @if($lastRunAt)
                    <div class="text-xs text-gray-500">{{ $lastRunAt->toDayDateTimeString() }}</div>
                @endif
            </div>
        </div>
    </div>

    {{-- Main grid: runs (2/3) + drilldown (1/3) --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- Runs table --}}
        <div class="lg:col-span-2 rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h3 class="text-sm font-semibold text-gray-900">Recent runs</h3>
                <p class="text-xs text-gray-500">Last {{ $runs->count() }} runs across all teams (ordered by start time).</p>
            </div>
            @if($runs->isEmpty())
                <div class="px-5 py-10 text-center text-sm text-gray-500">
                    No watchdog runs yet. Once <code class="text-xs">sentry:watchdog</code> executes for the first time, runs will appear here.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-2 text-left font-semibold">Started</th>
                                <th class="px-4 py-2 text-left font-semibold">Team / Integration</th>
                                <th class="px-4 py-2 text-right font-semibold">Triaged</th>
                                <th class="px-4 py-2 text-right font-semibold">PRs</th>
                                <th class="px-4 py-2 text-right font-semibold">Investigate</th>
                                <th class="px-4 py-2 text-right font-semibold">Critical</th>
                                <th class="px-4 py-2 text-left font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($runs as $run)
                                <tr wire:key="run-{{ $run->id }}"
                                    wire:click="selectRun('{{ $run->id }}')"
                                    class="cursor-pointer hover:bg-gray-50 {{ $selectedRun?->id === $run->id ? 'bg-primary-50' : '' }}">
                                    <td class="px-4 py-2 align-top">
                                        <div class="text-gray-900">{{ $run->started_at->format('M j, H:i') }}</div>
                                        <div class="text-xs text-gray-500">{{ $run->started_at->diffForHumans() }}</div>
                                    </td>
                                    <td class="px-4 py-2 align-top">
                                        <div class="text-gray-900">{{ $run->team?->name ?? '—' }}</div>
                                        <div class="text-xs text-gray-500">{{ $run->integration?->name ?? 'Integration removed' }}</div>
                                    </td>
                                    <td class="px-4 py-2 text-right align-top text-gray-900">{{ number_format($run->signals_triaged) }}</td>
                                    <td class="px-4 py-2 text-right align-top text-gray-900">{{ number_format($run->prs_opened) }}</td>
                                    <td class="px-4 py-2 text-right align-top text-gray-900">{{ number_format($run->investigate_only) }}</td>
                                    <td class="px-4 py-2 text-right align-top">
                                        <span class="{{ $run->critical_count > 0 ? 'font-semibold text-red-600' : 'text-gray-900' }}">
                                            {{ number_format($run->critical_count) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 align-top">
                                        @if($run->isFinished())
                                            <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">finished</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">running</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Selected run drilldown --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4">
                <h3 class="text-sm font-semibold text-gray-900">Run details</h3>
                @if($selectedRun)
                    <p class="text-xs text-gray-500">{{ $selectedRun->started_at->toDayDateTimeString() }}</p>
                @endif
            </div>

            @if($selectedRun === null)
                <div class="px-5 py-10 text-center text-sm text-gray-500">
                    Select a run from the table to see its digest and the Sentry signals it processed.
                </div>
            @else
                <div class="space-y-4 px-5 py-4">
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <span class="text-gray-500">Team</span>
                            <div class="font-medium text-gray-900">{{ $selectedRun->team?->name ?? '—' }}</div>
                        </div>
                        <div>
                            <span class="text-gray-500">Integration</span>
                            <div class="font-medium text-gray-900">{{ $selectedRun->integration?->name ?? '—' }}</div>
                        </div>
                        <div>
                            <span class="text-gray-500">Duration</span>
                            <div class="font-medium text-gray-900">
                                @if($selectedRun->finished_at)
                                    {{ $selectedRun->started_at->diffInSeconds($selectedRun->finished_at) }}s
                                @else
                                    <span class="text-amber-700">in progress</span>
                                @endif
                            </div>
                        </div>
                        <div>
                            <span class="text-gray-500">Counts</span>
                            <div class="font-medium text-gray-900">
                                {{ $selectedRun->signals_triaged }} triaged · {{ $selectedRun->prs_opened }} PRs · {{ $selectedRun->critical_count }} critical
                            </div>
                        </div>
                    </div>

                    @if($selectedRun->digest_summary)
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Digest summary</div>
                            <pre class="mt-1 max-h-64 overflow-auto rounded-md bg-gray-50 p-3 text-xs leading-snug text-gray-800 whitespace-pre-wrap">{{ $selectedRun->digest_summary }}</pre>
                        </div>
                    @endif

                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Related Sentry signals ({{ $relatedSignals->count() }})
                        </div>
                        @if($relatedSignals->isEmpty())
                            <p class="mt-2 text-sm text-gray-500">No matching signals found in the run window.</p>
                        @else
                            <ul class="mt-2 space-y-2">
                                @foreach($relatedSignals as $signal)
                                    @php
                                        $title = data_get($signal->payload, 'payload.title')
                                            ?? data_get($signal->payload, 'title')
                                            ?? $signal->source_native_id
                                            ?? $signal->id;
                                        $permalink = data_get($signal->payload, 'payload.permalink')
                                            ?? data_get($signal->payload, 'permalink');
                                    @endphp
                                    <li class="rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-xs">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="flex-1 min-w-0">
                                                <div class="truncate font-medium text-gray-900">{{ $title }}</div>
                                                <div class="mt-0.5 text-gray-500">
                                                    {{ $signal->status?->value ?? '—' }}
                                                    · updated {{ $signal->updated_at?->diffForHumans() }}
                                                </div>
                                            </div>
                                            @if($permalink)
                                                <a href="{{ $permalink }}" target="_blank" rel="noopener"
                                                    class="shrink-0 text-primary-600 hover:text-primary-500">Sentry ↗</a>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
