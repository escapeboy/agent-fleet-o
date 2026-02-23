<div wire:poll.5s>
    {{-- Stuck Experiments --}}
    @if($stuckExperiments->isNotEmpty())
        <div class="mb-6 rounded-xl border border-yellow-300 bg-yellow-50 p-6">
            <h3 class="text-sm font-medium text-yellow-800">Stuck Experiments ({{ $stuckExperiments->count() }})</h3>
            <div class="mt-4 space-y-3">
                @foreach($stuckExperiments as $stuck)
                    <div class="flex items-center justify-between rounded-lg bg-white p-3 shadow-sm">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('experiments.show', $stuck->experiment) }}"
                                class="text-sm font-medium text-gray-900 hover:text-primary-600">
                                {{ Str::limit($stuck->experiment->title, 50) }}
                            </a>
                            <p class="mt-0.5 text-xs text-gray-500">
                                Stuck in <span class="font-medium text-yellow-700">{{ str_replace('_', ' ', $stuck->state) }}</span>
                                for {{ $stuck->stuck_duration }}
                                @if($stuck->recovery_attempts > 0)
                                    &middot; {{ $stuck->recovery_attempts }} recovery attempt(s)
                                @endif
                                @if($stuck->last_recovery_at)
                                    &middot; Last recovery {{ $stuck->last_recovery_at->diffForHumans() }}
                                @endif
                            </p>
                        </div>
                        <div class="ml-4 flex items-center gap-2">
                            <button wire:click="retryExperiment('{{ $stuck->experiment->id }}')"
                                wire:confirm="Re-trigger recovery for this experiment?"
                                class="rounded-md bg-yellow-100 px-2.5 py-1.5 text-xs font-medium text-yellow-800 hover:bg-yellow-200">
                                Retry
                            </button>
                            <button wire:click="killExperiment('{{ $stuck->experiment->id }}')"
                                wire:confirm="Kill this stuck experiment? This cannot be undone."
                                class="rounded-md bg-red-100 px-2.5 py-1.5 text-xs font-medium text-red-800 hover:bg-red-200">
                                Kill
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

        {{-- Queue Health --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="text-sm font-medium text-gray-500">Queue Health</h3>
            <div class="mt-4 space-y-3">
                @foreach($queueStats as $queue => $stats)
                    <div class="flex items-center justify-between rounded-lg bg-gray-50 p-3">
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ $queue }}</p>
                            <p class="text-xs text-gray-500">
                                {{ $stats['reserved'] }} processing
                                @if($stats['delayed'] > 0)
                                    &middot; {{ $stats['delayed'] }} delayed
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-lg font-semibold {{ $stats['size'] > 50 ? 'text-red-600' : ($stats['size'] > 10 ? 'text-yellow-600' : 'text-gray-900') }}">{{ $stats['size'] }}</span>
                            <span class="text-xs text-gray-400">queued</span>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-3">
                <a href="/horizon" target="_blank" class="text-xs text-primary-600 hover:text-primary-800">Open Horizon Dashboard</a>
            </div>
        </div>

        {{-- Provider Health --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="text-sm font-medium text-gray-500">Provider Health</h3>
            <div class="mt-4 space-y-3">
                @forelse($agents as $agent)
                    <div class="flex items-center justify-between rounded-lg bg-gray-50 p-3">
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ $agent->name }}</p>
                            <p class="text-xs text-gray-500">
                                {{ $agent->provider }} / {{ $agent->model }}
                                @if($agent->circuitBreakerState)
                                    &middot; CB: {{ $agent->circuitBreakerState->state }}
                                    @if($agent->circuitBreakerState->failure_count > 0)
                                        ({{ $agent->circuitBreakerState->failure_count }} failures)
                                    @endif
                                @endif
                            </p>
                        </div>
                        <x-status-badge :status="$agent->status->value" />
                    </div>
                @empty
                    <p class="text-sm text-gray-400">No agents configured.</p>
                @endforelse
            </div>
        </div>

        {{-- Spend Monitor --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="text-sm font-medium text-gray-500">Spend Monitor</h3>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <p class="text-xs text-gray-500">This Hour</p>
                    <p class="text-lg font-semibold text-gray-900">{{ number_format($spendStats['this_hour']) }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Today</p>
                    <p class="text-lg font-semibold text-gray-900">{{ number_format($spendStats['today']) }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Total Spent</p>
                    <p class="text-lg font-semibold text-gray-900">{{ number_format($spendStats['total_spent']) }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Active Budget Cap</p>
                    <p class="text-lg font-semibold text-gray-900">{{ number_format($spendStats['total_budget_cap']) }}</p>
                </div>
            </div>
            @if($spendStats['total_budget_cap'] > 0)
                @php $pct = min(100, round(($spendStats['total_spent'] / $spendStats['total_budget_cap']) * 100)); @endphp
                <div class="mt-3">
                    <div class="flex items-center justify-between text-xs text-gray-500">
                        <span>Overall utilization</span>
                        <span>{{ $pct }}%</span>
                    </div>
                    <div class="mt-1 h-2 w-full rounded-full bg-gray-200">
                        <div class="h-2 rounded-full {{ $pct > 80 ? 'bg-red-500' : ($pct > 50 ? 'bg-yellow-500' : 'bg-green-500') }}" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Connector Health --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="text-sm font-medium text-gray-500">Connector Health</h3>
            <div class="mt-4 space-y-3">
                @forelse($connectorStats as $conn)
                    <div class="flex items-center justify-between rounded-lg {{ $conn->is_healthy ? 'bg-gray-50' : 'bg-red-50' }} p-3">
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ $conn->name }}</p>
                            <p class="text-xs text-gray-500">
                                {{ $conn->driver }}
                                @if($conn->last_success_at)
                                    &middot; Last poll {{ $conn->last_success_at->diffForHumans() }}
                                @else
                                    &middot; Never polled
                                @endif
                                @if($conn->last_error_message)
                                    &middot; <span class="text-red-500">{{ Str::limit($conn->last_error_message, 60) }}</span>
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-medium text-gray-700">{{ $conn->signals_24h }}</span>
                            <span class="text-xs text-gray-400">24h</span>
                            <span class="inline-flex h-2 w-2 rounded-full {{ $conn->is_healthy ? 'bg-green-400' : 'bg-red-400' }}"></span>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">No active connectors.</p>
                @endforelse
            </div>
        </div>

        {{-- Recent Errors --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="text-sm font-medium text-gray-500">Recent Errors</h3>
            <div class="mt-4 space-y-3">
                @forelse($recentErrors as $error)
                    <div class="rounded-lg bg-red-50 p-3">
                        <div class="flex items-center justify-between">
                            <a href="{{ route('experiments.show', $error->experiment) }}"
                                class="text-sm font-medium text-red-800 hover:text-red-900">
                                {{ $error->experiment->title }}
                            </a>
                            <span class="text-xs text-red-500">{{ $error->updated_at->diffForHumans() }}</span>
                        </div>
                        <p class="mt-1 text-xs text-red-600">
                            {{ str_replace('_', ' ', ucfirst($error->stage->value)) }} &middot; Iteration {{ $error->iteration }}
                            @if($error->retry_count > 0)
                                &middot; {{ $error->retry_count }} retries
                            @endif
                        </p>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">No recent errors.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
