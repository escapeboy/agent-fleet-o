<div>
    {{-- Flash message --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <div class="flex items-center gap-3">
                <h2 class="text-xl font-semibold text-gray-900">{{ $project->title }}</h2>
                <x-status-badge :status="$project->status->value" />
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                    {{ $project->type === \App\Domain\Project\Enums\ProjectType::Continuous ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-700' }}">
                    {{ $project->type->label() }}
                </span>
            </div>
            @if($project->description)
                <p class="mt-1 max-w-2xl text-sm text-gray-500">{{ $project->description }}</p>
            @endif
        </div>
        <div class="flex items-center gap-2">
            @if($project->status !== \App\Domain\Project\Enums\ProjectStatus::Archived)
                <a href="{{ route('projects.edit', $project) }}"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Edit
                </a>
            @endif
            @if($project->status === \App\Domain\Project\Enums\ProjectStatus::Draft)
                <button wire:click="activate"
                    class="rounded-lg bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700">
                    Activate
                </button>
            @elseif($project->status === \App\Domain\Project\Enums\ProjectStatus::Active)
                <button wire:click="triggerRun"
                    class="rounded-lg border border-primary-300 px-3 py-1.5 text-sm font-medium text-primary-700 hover:bg-primary-50">
                    Trigger Run
                </button>
                <button wire:click="pause"
                    class="rounded-lg border border-yellow-300 px-3 py-1.5 text-sm font-medium text-yellow-700 hover:bg-yellow-50">
                    Pause
                </button>
            @elseif($project->status === \App\Domain\Project\Enums\ProjectStatus::Paused)
                <button wire:click="resume"
                    class="rounded-lg border border-green-300 px-3 py-1.5 text-sm font-medium text-green-700 hover:bg-green-50">
                    Resume
                </button>
            @endif
            @if($project->status->canTransitionTo(\App\Domain\Project\Enums\ProjectStatus::Archived))
                <button wire:click="archive" wire:confirm="Archive this project? It cannot be reactivated."
                    class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">
                    Archive
                </button>
            @endif
        </div>
    </div>

    {{-- Stats Cards --}}
    <div class="mb-6 grid grid-cols-5 gap-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="text-2xl font-bold text-gray-900">{{ $totalRuns }}</div>
            <div class="text-sm text-gray-500">Total Runs</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="text-2xl font-bold text-green-600">{{ $successfulRuns }}</div>
            <div class="text-sm text-gray-500">Successful</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="text-2xl font-bold text-red-600">{{ $failedRuns }}</div>
            <div class="text-sm text-gray-500">Failed</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="text-2xl font-bold text-gray-900">{{ number_format($project->total_spend_credits) }}</div>
            <div class="text-sm text-gray-500">Credits Spent</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            @if($project->schedule && $project->schedule->next_run_at)
                <div class="text-lg font-bold text-gray-900">{{ $project->schedule->next_run_at->diffForHumans() }}</div>
                <div class="text-sm text-gray-500">Next Run</div>
            @else
                <div class="text-lg font-bold text-gray-400">--</div>
                <div class="text-sm text-gray-500">Next Run</div>
            @endif
        </div>
    </div>

    {{-- Budget Progress (if caps exist) --}}
    @php
        $budgetConfig = $project->budget_config;
        $hasBudget = ! empty($budgetConfig['daily_cap']) || ! empty($budgetConfig['weekly_cap']) || ! empty($budgetConfig['monthly_cap']);
    @endphp
    @if($hasBudget)
        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-4">
            <h3 class="mb-3 text-sm font-semibold text-gray-700">Budget Usage</h3>
            <div class="grid grid-cols-3 gap-4">
                @foreach(['daily', 'weekly', 'monthly'] as $period)
                    @php
                        $cap = $budgetConfig["{$period}_cap"] ?? null;
                        $spend = $cap ? $project->periodSpend($period) : 0;
                        $pct = $cap ? min(100, round(($spend / $cap) * 100)) : 0;
                    @endphp
                    @if($cap)
                        <div>
                            <div class="mb-1 flex items-center justify-between text-xs">
                                <span class="font-medium text-gray-600">{{ ucfirst($period) }}</span>
                                <span class="text-gray-500">{{ $spend }}/{{ $cap }} credits</span>
                            </div>
                            <div class="h-2 w-full rounded-full bg-gray-200">
                                <div class="h-2 rounded-full {{ $pct >= 100 ? 'bg-red-500' : ($pct >= 80 ? 'bg-yellow-500' : 'bg-green-500') }}" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    {{-- Dependencies --}}
    @if($upstreamDeps->isNotEmpty() || $downstreamDeps->isNotEmpty())
        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-4">
            <h3 class="mb-3 text-sm font-semibold text-gray-700">Dependencies</h3>
            <div class="grid gap-4 {{ $upstreamDeps->isNotEmpty() && $downstreamDeps->isNotEmpty() ? 'grid-cols-2' : 'grid-cols-1' }}">
                @if($upstreamDeps->isNotEmpty())
                    <div>
                        <div class="mb-2 text-xs font-medium uppercase tracking-wider text-gray-400">Uses results from</div>
                        <div class="space-y-2">
                            @foreach($upstreamDeps as $dep)
                                <a href="{{ route('projects.show', $dep->dependsOn) }}"
                                    class="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2 hover:bg-gray-50">
                                    <div class="flex items-center gap-2">
                                        <svg class="h-4 w-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16l-4-4m0 0l4-4m-4 4h18"/></svg>
                                        <span class="text-sm font-medium text-gray-900">{{ $dep->dependsOn->title }}</span>
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500">{{ $dep->alias }}</span>
                                    </div>
                                    <span class="text-xs text-gray-400">{{ $dep->reference_type === 'latest_run' ? 'latest run' : 'pinned' }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if($downstreamDeps->isNotEmpty())
                    <div>
                        <div class="mb-2 text-xs font-medium uppercase tracking-wider text-gray-400">Provides results to</div>
                        <div class="space-y-2">
                            @foreach($downstreamDeps as $dep)
                                <a href="{{ route('projects.show', $dep->project) }}"
                                    class="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2 hover:bg-gray-50">
                                    <div class="flex items-center gap-2">
                                        <svg class="h-4 w-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                                        <span class="text-sm font-medium text-gray-900">{{ $dep->project->title }}</span>
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500">{{ $dep->alias }}</span>
                                    </div>
                                    <x-status-badge :status="$dep->project->status->value" />
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Tools & Credentials --}}
    @if($allowedTools->isNotEmpty() || $allowedCredentials->isNotEmpty())
        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-4">
            <h3 class="mb-3 text-sm font-semibold text-gray-700">Tools & Credentials</h3>
            <div class="grid gap-4 {{ $allowedTools->isNotEmpty() && $allowedCredentials->isNotEmpty() ? 'grid-cols-2' : 'grid-cols-1' }}">
                @if($allowedTools->isNotEmpty())
                    <div>
                        <div class="mb-2 text-xs font-medium uppercase tracking-wider text-gray-400">Tools</div>
                        <div class="flex flex-wrap gap-2">
                            @foreach($allowedTools as $tool)
                                <a href="{{ route('tools.show', $tool) }}"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-100 px-3 py-1.5 text-sm hover:bg-gray-50">
                                    <span class="font-medium text-gray-700">{{ $tool->name }}</span>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ match($tool->type->value) {
                                        'mcp_stdio' => 'bg-blue-100 text-blue-800',
                                        'mcp_http' => 'bg-cyan-100 text-cyan-800',
                                        'built_in' => 'bg-amber-100 text-amber-800',
                                        default => 'bg-gray-100 text-gray-800',
                                    } }}">{{ $tool->type->label() }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if($allowedCredentials->isNotEmpty())
                    <div>
                        <div class="mb-2 text-xs font-medium uppercase tracking-wider text-gray-400">Credentials</div>
                        <div class="flex flex-wrap gap-2">
                            @foreach($allowedCredentials as $credential)
                                <a href="{{ route('credentials.show', $credential) }}"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-100 px-3 py-1.5 text-sm hover:bg-gray-50">
                                    <span class="font-medium text-gray-700">{{ $credential->name }}</span>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $credential->credential_type->color() }}">{{ $credential->credential_type->label() }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Tabs --}}
    <div class="mb-4 border-b border-gray-200">
        <nav class="-mb-px flex space-x-8">
            @foreach(['activity' => 'Activity', 'milestones' => 'Milestones', 'runs' => 'Runs'] as $tab => $label)
                <button wire:click="$set('activeTab', '{{ $tab }}')"
                    class="whitespace-nowrap border-b-2 py-3 text-sm font-medium {{ $activeTab === $tab ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                    {{ $label }}
                    @if($tab === 'milestones')
                        <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $milestones->count() }}</span>
                    @endif
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Tab Content --}}
    @if($activeTab === 'activity')
        <div @if($project->status === \App\Domain\Project\Enums\ProjectStatus::Active) wire:poll.30s @endif>
            <livewire:projects.project-activity-timeline :project="$project" :key="'timeline-' . $project->id" />
        </div>

    @elseif($activeTab === 'milestones')
        <div class="space-y-3">
            @forelse($milestones as $milestone)
                <div class="flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full
                        {{ $milestone->status === \App\Domain\Project\Enums\MilestoneStatus::Completed ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400' }}">
                        @if($milestone->status === \App\Domain\Project\Enums\MilestoneStatus::Completed)
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                        @else
                            <span class="text-sm font-medium">{{ $milestone->sort_order }}</span>
                        @endif
                    </div>
                    <div class="flex-1">
                        <div class="font-medium text-gray-900">{{ $milestone->title }}</div>
                        @if($milestone->target_value)
                            <div class="text-xs text-gray-500">Target: {{ $milestone->target_value }} {{ $milestone->target_metric }}</div>
                        @endif
                    </div>
                    <div>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                            {{ $milestone->status === \App\Domain\Project\Enums\MilestoneStatus::Completed ? 'bg-green-100 text-green-700' : ($milestone->status === \App\Domain\Project\Enums\MilestoneStatus::InProgress ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600') }}">
                            {{ $milestone->status->label() }}
                        </span>
                    </div>
                    @if($milestone->completed_at)
                        <div class="text-xs text-gray-400">{{ $milestone->completed_at->diffForHumans() }}</div>
                    @endif
                </div>
            @empty
                <div class="rounded-xl border border-gray-200 bg-white px-6 py-12 text-center text-sm text-gray-400">
                    No milestones defined for this project.
                </div>
            @endforelse
        </div>

    @elseif($activeTab === 'runs')
        <livewire:projects.project-runs-table :project="$project" :key="'runs-' . $project->id" />
    @endif
</div>
