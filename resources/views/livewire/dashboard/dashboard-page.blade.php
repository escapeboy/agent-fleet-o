<div wire:poll.5s>
    {{-- KPI Cards --}}
    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Active Runs" :value="$active" />
        <x-stat-card label="Success Rate" :value="$successRate . '%'" :change="$completed . ' of ' . $total . ' completed'" changeType="neutral" />
        <x-stat-card label="Total Spend" :value="number_format($totalSpend) . ' credits'" />
        <x-stat-card label="Pending Approvals" :value="$pendingApprovals"
            :change="$pendingApprovals > 0 ? 'Action required' : 'All clear'"
            :changeType="$pendingApprovals > 0 ? 'negative' : 'positive'" />
    </div>

    {{-- Skills, Agents & Projects KPIs --}}
    <div class="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-3 lg:grid-cols-6">
        <x-stat-card label="Active Skills" :value="$activeSkills" />
        <x-stat-card label="Active Agents" :value="$activeAgents" />
        <x-stat-card label="Skill Executions (24h)" :value="number_format($skillExecutions24h)" />
        <x-stat-card label="Agent Runs (24h)" :value="number_format($agentRuns24h)" />
        <x-stat-card label="Active Projects" :value="$activeProjects" />
        <x-stat-card label="Project Runs (24h)" :value="number_format($projectRuns24h)" />
    </div>

    <div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Active Runs --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-medium text-gray-500">Active Runs</h3>
                <a href="{{ route('projects.index') }}" class="text-xs text-primary-600 hover:text-primary-800">View all</a>
            </div>
            <div class="mt-4 space-y-3">
                @forelse($activeExperiments as $experiment)
                    <a href="{{ route('experiments.show', $experiment) }}" class="flex items-center justify-between rounded-lg bg-gray-50 p-3 transition hover:bg-gray-100">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900">{{ $experiment->title }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">Step {{ $experiment->current_iteration }}/{{ $experiment->max_iterations }}</p>
                        </div>
                        <x-status-badge :status="$experiment->status->value" class="ml-3 shrink-0" />
                    </a>
                @empty
                    <p class="text-sm text-gray-400">No active runs.</p>
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

    {{-- Spend Forecast Widget --}}
    @if($spendForecast['total_spent'] > 0)
        <div class="mt-6 rounded-xl border border-gray-200 bg-white p-6">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-sm font-medium text-gray-500">AI Spend Forecast</h3>
                @php
                    $trendColor = match($spendForecast['trend']) {
                        'up' => 'text-red-600',
                        'down' => 'text-green-600',
                        default => 'text-gray-500',
                    };
                    $trendLabel = match($spendForecast['trend']) {
                        'up' => '↑ trending up',
                        'down' => '↓ trending down',
                        default => '→ stable',
                    };
                @endphp
                <span class="text-xs font-medium {{ $trendColor }}">{{ $trendLabel }}</span>
            </div>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <p class="text-xs text-gray-400">Daily avg (7d)</p>
                    <p class="mt-1 text-lg font-semibold text-gray-900">{{ number_format($spendForecast['daily_avg_7d']) }}</p>
                    <p class="text-xs text-gray-400">credits/day</p>
                </div>
                <div>
                    <p class="text-xs text-gray-400">Daily avg (30d)</p>
                    <p class="mt-1 text-lg font-semibold text-gray-900">{{ number_format($spendForecast['daily_avg_30d']) }}</p>
                    <p class="text-xs text-gray-400">credits/day</p>
                </div>
                <div>
                    <p class="text-xs text-gray-400">Projected (30d)</p>
                    <p class="mt-1 text-lg font-semibold {{ $spendForecast['budget_cap'] > 0 && $spendForecast['projected_30d'] > $spendForecast['budget_cap'] - $spendForecast['total_spent'] ? 'text-red-600' : 'text-gray-900' }}">
                        {{ number_format($spendForecast['projected_30d']) }}
                    </p>
                    <p class="text-xs text-gray-400">credits</p>
                </div>
                <div>
                    @if($spendForecast['days_until_cap'] !== null)
                        <p class="text-xs text-gray-400">Cap reached in</p>
                        <p class="mt-1 text-lg font-semibold {{ $spendForecast['days_until_cap'] <= 7 ? 'text-red-600' : ($spendForecast['days_until_cap'] <= 30 ? 'text-yellow-600' : 'text-gray-900') }}">
                            {{ $spendForecast['days_until_cap'] }}d
                        </p>
                        <p class="text-xs text-gray-400">at current rate</p>
                    @else
                        <p class="text-xs text-gray-400">Budget cap</p>
                        <p class="mt-1 text-lg font-semibold text-gray-400">No limit</p>
                    @endif
                </div>
            </div>

            {{-- 30-day spark chart --}}
            @php
                $series = $spendForecast['daily_series'];
                $maxSpend = max(array_column($series, 'spend')) ?: 1;
            @endphp
            <div class="mt-4 flex h-14 items-end gap-px">
                @foreach($series as $day)
                    @php
                        $barHeight = max(($day['spend'] / $maxSpend) * 100, $day['spend'] > 0 ? 4 : 0);
                        $isToday = $day['date'] === now()->format('Y-m-d');
                    @endphp
                    <div class="group relative flex-1"
                         title="{{ $day['date'] }}: {{ number_format($day['spend']) }} credits">
                        <div class="w-full rounded-sm transition-all {{ $isToday ? 'bg-primary-500' : 'bg-primary-200 hover:bg-primary-400' }}"
                             style="height: {{ $barHeight }}%"></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-1 flex justify-between text-xs text-gray-400">
                <span>30 days ago</span>
                <span>Today</span>
            </div>
        </div>
    @endif
</div>
