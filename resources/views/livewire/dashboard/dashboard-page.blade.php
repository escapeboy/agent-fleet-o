<div wire:poll.5s>
    {{-- KPI Cards --}}
    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Active Experiments" :value="$active" />
        <x-stat-card label="Success Rate" :value="$successRate . '%'" :change="$completed . ' of ' . $total . ' completed'" changeType="neutral" />
        <x-stat-card label="Total Spend" :value="number_format($totalSpend) . ' credits'" />
        <x-stat-card label="Pending Approvals" :value="$pendingApprovals"
            :change="$pendingApprovals > 0 ? 'Action required' : 'All clear'"
            :changeType="$pendingApprovals > 0 ? 'negative' : 'positive'" />
    </div>

    {{-- Skills & Agents KPIs --}}
    <div class="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Active Skills" :value="$activeSkills" />
        <x-stat-card label="Active Agents" :value="$activeAgents" />
        <x-stat-card label="Skill Executions (24h)" :value="number_format($skillExecutions24h)" />
        <x-stat-card label="Agent Runs (24h)" :value="number_format($agentRuns24h)" />
    </div>

    <div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Active Experiments --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-medium text-gray-500">Active Experiments</h3>
                <a href="{{ route('experiments.index') }}" class="text-xs text-primary-600 hover:text-primary-800">View all</a>
            </div>
            <div class="mt-4 space-y-3">
                @forelse($activeExperiments as $experiment)
                    <a href="{{ route('experiments.show', $experiment) }}" class="flex items-center justify-between rounded-lg bg-gray-50 p-3 transition hover:bg-gray-100">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900">{{ $experiment->title }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">Iteration {{ $experiment->current_iteration }}/{{ $experiment->max_iterations }}</p>
                        </div>
                        <x-status-badge :status="$experiment->status->value" class="ml-3 shrink-0" />
                    </a>
                @empty
                    <p class="text-sm text-gray-400">No active experiments.</p>
                @endforelse
            </div>
        </div>

        {{-- Alerts --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="text-sm font-medium text-gray-500">Alerts</h3>
            <div class="mt-4 space-y-3">
                @forelse($alerts as $alert)
                    <a href="{{ $alert['link'] }}" class="flex items-start gap-3 rounded-lg p-3 transition
                        {{ $alert['type'] === 'error' ? 'bg-red-50 hover:bg-red-100' : 'bg-yellow-50 hover:bg-yellow-100' }}">
                        @if($alert['type'] === 'error')
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                        @else
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-yellow-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                        @endif
                        <p class="text-sm {{ $alert['type'] === 'error' ? 'text-red-800' : 'text-yellow-800' }}">{{ $alert['message'] }}</p>
                    </a>
                @empty
                    <p class="text-sm text-gray-400">No alerts. All systems nominal.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
