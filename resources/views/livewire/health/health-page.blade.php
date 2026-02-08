<div wire:poll.5s>
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
