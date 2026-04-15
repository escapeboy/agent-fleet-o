<div wire:poll.5s.visible>

    {{-- Widget Customization Controls --}}
    <div x-data="{ open: false }" class="mb-4 flex justify-end">
        <button @click="open = !open" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 shadow-sm hover:bg-gray-50 transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" /></svg>
            Customize
        </button>
        <div x-show="open" x-transition @click.outside="open = false"
             class="absolute z-20 mt-8 mr-0 w-56 rounded-xl border border-gray-200 bg-white p-3 shadow-lg">
            <p class="mb-2 text-xs font-semibold text-gray-500 uppercase tracking-wide">Show Widgets</p>
            @foreach(['experiments' => 'Experiments', 'projects' => 'Projects', 'agents' => 'Agents', 'skills' => 'Skills', 'budget' => 'Budget', 'approvals' => 'Approvals', 'activity' => 'Activity', 'chatbots' => 'Chatbots'] as $key => $label)
                <label class="flex cursor-pointer items-center gap-2 rounded px-1 py-1 hover:bg-gray-50 text-sm text-gray-700">
                    <input type="checkbox"
                           class="h-3.5 w-3.5 rounded border-gray-300 text-primary-600"
                           wire:click="toggleWidget('{{ $key }}')"
                           @checked($widgets[$key] ?? true)>
                    {{ $label }}
                </label>
            @endforeach
        </div>
    </div>

    {{-- Bento KPI Grid --}}
    <div class="grid grid-cols-12 gap-4">

        {{-- Active Experiments — large featured card (7 cols) --}}
        <div class="col-span-12 flex min-h-36 flex-col rounded-xl border border-gray-200 bg-white p-5 sm:col-span-7">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Active Runs</p>
                    <p class="mt-1 text-4xl font-bold text-gray-900">{{ $active }}</p>
                </div>
                <a href="{{ route('experiments.index') }}" class="shrink-0 text-xs text-primary-600 hover:text-primary-800">View all</a>
            </div>

            {{-- Recent active experiments as status chips --}}
            @if($activeExperiments->isNotEmpty())
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach($activeExperiments->take(3) as $exp)
                        <a href="{{ route('experiments.show', $exp) }}"
                           class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-100 transition">
                            <span class="max-w-32 truncate">{{ $exp->title }}</span>
                            <x-status-badge :status="$exp->status->value" class="scale-90" />
                        </a>
                    @endforeach
                    @if($activeExperiments->count() > 3)
                        <span class="inline-flex items-center rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs text-gray-400">
                            +{{ $activeExperiments->count() - 3 }} more
                        </span>
                    @endif
                </div>
            @else
                <p class="mt-auto text-sm text-gray-400">No active runs.</p>
            @endif
        </div>

        {{-- Pending Approvals (5 cols) --}}
        <div class="col-span-12 sm:col-span-5">
            <a wire:navigate href="{{ route('approvals.index') }}"
               class="flex h-full min-h-36 flex-col rounded-xl border p-5 transition hover:shadow-sm
                   {{ $pendingApprovals > 0 ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-white' }}">
                <p class="text-xs font-medium uppercase tracking-wider {{ $pendingApprovals > 0 ? 'text-amber-500' : 'text-gray-400' }}">
                    Pending Approvals
                </p>
                <p class="mt-1 text-4xl font-bold {{ $pendingApprovals > 0 ? 'text-amber-700' : 'text-gray-900' }}">
                    {{ $pendingApprovals }}
                </p>
                <p class="mt-auto text-xs {{ $pendingApprovals > 0 ? 'text-amber-600' : 'text-gray-400' }}">
                    {{ $pendingApprovals > 0 ? 'Action required →' : 'All clear' }}
                </p>
            </a>
        </div>

        {{-- Row 2: Completed / Success Rate / Total Spend / Forecast --}}
        <div class="col-span-12 sm:col-span-3">
            <x-stat-card label="Completed" :value="$completed" :change="'of ' . $total . ' total'" changeType="neutral" />
        </div>
        <div class="col-span-12 sm:col-span-3">
            <x-stat-card label="Success Rate" :value="$successRate . '%'" :change="$completed . ' completed'" changeType="neutral" />
        </div>
        <div class="col-span-12 sm:col-span-3">
            <x-stat-card label="Total Spend" :value="number_format($totalSpend) . ' cr'" />
        </div>
        <div class="col-span-12 sm:col-span-3">
            @if($spendForecast['total_spent'] > 0)
                <x-stat-card label="Spend Forecast (30d)" :value="number_format($spendForecast['projected_30d']) . ' cr'" />
            @else
                <x-stat-card label="Spend Forecast" value="—" change="No spend yet" changeType="neutral" />
            @endif
        </div>

        {{-- Row 3: Active Agents / Active Skills / Active Projects --}}
        <div class="col-span-12 sm:col-span-4">
            <x-stat-card label="Active Agents" :value="$activeAgents" />
        </div>
        <div class="col-span-12 sm:col-span-4">
            <x-stat-card label="Active Skills" :value="$activeSkills" />
        </div>
        <div class="col-span-12 sm:col-span-4">
            <x-stat-card label="Active Projects" :value="$activeProjects" />
        </div>

        {{-- Row 4: Agent Runs 24h / Skill Executions 24h --}}
        <div class="col-span-12 sm:col-span-6">
            <x-stat-card label="Agent Runs (24h)" :value="number_format($agentRuns24h)" />
        </div>
        <div class="col-span-12 sm:col-span-6">
            <x-stat-card label="Skill Executions (24h)" :value="number_format($skillExecutions24h)" />
        </div>

        {{-- AI Routing (24h) --}}
        @if(($aiRoutingStats['total'] ?? 0) > 0)
            <div class="col-span-12 sm:col-span-4 rounded-xl border border-gray-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-400">AI Routing (24h)</p>
                <div class="mt-3 space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">Light</span>
                        <span class="font-medium text-gray-900">{{ $aiRoutingStats['light'] }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">Standard</span>
                        <span class="font-medium text-gray-900">{{ $aiRoutingStats['standard'] }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">Heavy</span>
                        <span class="font-medium text-gray-900">{{ $aiRoutingStats['heavy'] }}</span>
                    </div>
                    @if(($aiRoutingStats['escalated'] ?? 0) > 0)
                        <div class="flex justify-between text-sm">
                            <span class="text-amber-600">Escalated</span>
                            <span class="font-medium text-amber-700">{{ $aiRoutingStats['escalated'] }}</span>
                        </div>
                    @endif
                    @if(($aiRoutingStats['verification_failed'] ?? 0) > 0)
                        <div class="flex justify-between text-sm">
                            <span class="text-red-600">Verification Failed</span>
                            <span class="font-medium text-red-700">{{ $aiRoutingStats['verification_failed'] }}</span>
                        </div>
                    @endif
                </div>
                <a href="{{ route('metrics.ai-routing') }}" class="mt-3 block text-xs text-primary-600 hover:text-primary-800">View details &rarr;</a>
            </div>
        @endif

    </div>

    {{-- Usage Progress Bar --}}
    <div class="mt-4 rounded-xl border border-gray-200 bg-white p-4">
        @php
            $runsTotal = max(1, $agentRuns24h + $skillExecutions24h + $projectRuns24h);
            $agentPct = min(100, ($agentRuns24h / $runsTotal) * 100);
            $skillPct = min(100, ($skillExecutions24h / $runsTotal) * 100);
            $projectPct = min(100, ($projectRuns24h / $runsTotal) * 100);
            $totalActivity = $agentRuns24h + $skillExecutions24h + $projectRuns24h;
        @endphp
        <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-gray-700">Activity Today</span>
            <span class="text-sm text-gray-500">{{ number_format($totalActivity) }} total operations (24h)</span>
        </div>
        {{-- Segmented bar: agents / skills / project runs --}}
        <div class="mt-2 flex h-2 w-full overflow-hidden rounded-full bg-gray-100">
            @if($agentRuns24h > 0)
                <div class="h-2 bg-primary-500 transition-all" style="width: {{ $agentPct }}%" title="Agent runs: {{ $agentRuns24h }}"></div>
            @endif
            @if($skillExecutions24h > 0)
                <div class="h-2 bg-violet-400 transition-all" style="width: {{ $skillPct }}%" title="Skill executions: {{ $skillExecutions24h }}"></div>
            @endif
            @if($projectRuns24h > 0)
                <div class="h-2 bg-sky-400 transition-all" style="width: {{ $projectPct }}%" title="Project runs: {{ $projectRuns24h }}"></div>
            @endif
            @if($totalActivity === 0)
                <div class="h-2 w-full rounded-full bg-gray-100"></div>
            @endif
        </div>
        <div class="mt-2 flex flex-wrap gap-4 text-xs text-gray-500">
            <span class="flex items-center gap-1.5">
                <span class="inline-block h-2 w-2 rounded-full bg-primary-500"></span>
                Agent runs
            </span>
            <span class="flex items-center gap-1.5">
                <span class="inline-block h-2 w-2 rounded-full bg-violet-400"></span>
                Skill executions
            </span>
            <span class="flex items-center gap-1.5">
                <span class="inline-block h-2 w-2 rounded-full bg-sky-400"></span>
                Project runs
            </span>
        </div>
    </div>

    {{-- Quick Start — shown when no AI provider keys are configured --}}
    @if(!$hasProviderKeys)
        <div class="mt-6 rounded-xl border border-blue-200 bg-blue-50 p-6">
            <div class="flex items-start gap-4">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-100">
                    <svg class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456Z"/></svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-base font-semibold text-blue-900">Quick Start — Connect an AI Provider</h3>
                    <p class="mt-1 text-sm text-blue-800">
                        FleetQ needs an AI provider to power agents, skills, and workflows. Free options available — no credit card needed.
                    </p>
                    <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <div class="rounded-lg bg-white/80 px-3 py-2">
                            <p class="text-sm font-medium text-gray-900">Groq</p>
                            <p class="text-xs text-gray-500">Llama 3.3 70B, free tier</p>
                            <a href="https://console.groq.com/keys" target="_blank" class="mt-1 inline-block text-xs font-medium text-primary-600 hover:text-primary-800">Get free key &rarr;</a>
                        </div>
                        <div class="rounded-lg bg-white/80 px-3 py-2">
                            <p class="text-sm font-medium text-gray-900">OpenRouter</p>
                            <p class="text-xs text-gray-500">28 free models</p>
                            <a href="https://openrouter.ai/keys" target="_blank" class="mt-1 inline-block text-xs font-medium text-primary-600 hover:text-primary-800">Get free key &rarr;</a>
                        </div>
                        <div class="rounded-lg bg-white/80 px-3 py-2">
                            <p class="text-sm font-medium text-gray-900">Google AI</p>
                            <p class="text-xs text-gray-500">Gemini Flash, free tier</p>
                            <a href="https://aistudio.google.com/apikey" target="_blank" class="mt-1 inline-block text-xs font-medium text-primary-600 hover:text-primary-800">Get free key &rarr;</a>
                        </div>
                    </div>
                    <div class="mt-3">
                        <a href="{{ route('team.settings') }}" class="inline-flex items-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                            Add API Key in Settings
                        </a>
                    </div>
                </div>
            </div>
        </div>
    @endif

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

    {{-- Chatbot KPIs (only when chatbot feature is enabled) --}}
    @if($chatbotEnabled && $chatbotKpis)
        <div class="mt-6 rounded-xl border border-gray-200 bg-white p-6">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900">Chatbots</h3>
                <a href="{{ route('chatbots.index') }}" class="text-xs text-primary-600 hover:text-primary-800">View all</a>
            </div>
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-400">Active Chatbots</dt>
                    <dd class="mt-1 text-2xl font-semibold text-gray-900">{{ $chatbotKpis['active_chatbots'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-400">Sessions Today</dt>
                    <dd class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format($chatbotKpis['sessions_today']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-400">Escalations Today</dt>
                    <dd class="mt-1 text-2xl font-semibold {{ $chatbotKpis['escalations_today'] > 0 ? 'text-amber-600' : 'text-gray-900' }}">
                        {{ $chatbotKpis['escalations_today'] }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-400">Avg Confidence (7d)</dt>
                    <dd class="mt-1 text-2xl font-semibold text-gray-900">
                        {{ $chatbotKpis['avg_confidence_7d'] ? number_format((float)$chatbotKpis['avg_confidence_7d'] * 100, 0).'%' : '—' }}
                    </dd>
                </div>
            </dl>
        </div>
    @endif
</div>

@script
<script>
    // WebMCP: expose dashboard KPIs to browser AI agents
    if (window.FleetQWebMcp?.isAvailable()) {
        window.FleetQWebMcp.registerTool({
            name: 'dashboard_get_kpis',
            description: 'Get current FleetQ dashboard KPIs: active runs, success rate, total spend, pending approvals, active skills, active agents, and more.',
            inputSchema: { type: 'object', properties: {} },
            annotations: { readOnlyHint: true },
            execute: async () => {
                const kpis = {
                    active_runs: $wire.active,
                    success_rate_pct: $wire.successRate,
                    completed: $wire.completed,
                    total: $wire.total,
                    total_spend_credits: $wire.totalSpend,
                    pending_approvals: $wire.pendingApprovals,
                    active_skills: $wire.activeSkills,
                    active_agents: $wire.activeAgents,
                    skill_executions_24h: $wire.skillExecutions24h,
                    agent_runs_24h: $wire.agentRuns24h,
                    active_projects: $wire.activeProjects,
                    project_runs_24h: $wire.projectRuns24h,
                };
                return { content: [{ type: 'text', text: JSON.stringify(kpis) }] };
            }
        });
    }
</script>
@endscript
